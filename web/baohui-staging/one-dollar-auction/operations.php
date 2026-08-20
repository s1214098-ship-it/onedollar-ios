<?php
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'member-sync-bridge.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ops-document-no.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
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

function baohui_main_data(): array {
    $db = dirname(__DIR__) . '/data/baohui.sqlite';
    if (!is_file($db)) return [];
    try {
        $pdo = new PDO('sqlite:' . $db);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->query('SELECT json_data FROM app_data WHERE id = 1');
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        $data = json_decode((string)($row['json_data'] ?? ''), true);
        return is_array($data) ? $data : [];
    } catch (Throwable $e) {
        return [];
    }
}

function baohui_main_employees(): array {
    $data = baohui_main_data();
    $rows = $data['employees'] ?? [];
    if (!is_array($rows)) return [];
    $employees = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') continue;
        $employees[$name] = [
            'id' => (string)($row['id'] ?? ''),
            'account' => $name,
            'name' => $name,
            'department' => trim((string)($row['department'] ?? '')),
            'phone' => trim((string)($row['phone'] ?? '')),
        ];
    }
    ksort($employees, SORT_NATURAL);
    return array_values($employees);
}

function baohui_session_logged_in(): bool
{
    return !empty($_SESSION['baohui_logged_in'])
        || !empty($_SESSION['logged_in'])
        || !empty($_SESSION['admin_logged_in'])
        || !empty($_SESSION['is_login'])
        || (!empty($_SESSION['user']) && is_array($_SESSION['user']));
}

function baohui_session_user_name(): string
{
    foreach (['baohui_user', 'username', 'account', 'admin_user', 'user_name', 'name'] as $key) {
        if (!empty($_SESSION[$key]) && !is_array($_SESSION[$key])) {
            return trim((string)$_SESSION[$key]);
        }
    }
    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        foreach (['account', 'name', 'username', 'id'] as $key) {
            if (!empty($_SESSION['user'][$key])) {
                return trim((string)$_SESSION['user'][$key]);
            }
        }
    }
    return 'admin';
}

function baohui_session_role(): string
{
    foreach (['baohui_role', 'role', 'admin_role', 'user_role'] as $key) {
        if (!empty($_SESSION[$key]) && !is_array($_SESSION[$key])) {
            return trim((string)$_SESSION[$key]);
        }
    }
    if (!empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['role'])) {
        return trim((string)$_SESSION['user']['role']);
    }
    if (!empty($_SESSION['baohui_is_admin']) || !empty($_SESSION['is_admin'])) return '最高管理員';
    return '';
}

function baohui_can_approve_inventory_count(): bool
{
    if (!empty($_SESSION['baohui_is_admin']) || !empty($_SESSION['is_admin'])) return true;
    $user = strtolower(baohui_session_user_name());
    if (in_array($user, ['admin', 'administrator', 'root'], true)) return true;
    $role = baohui_session_role();
    return in_array($role, ['最高管理員', '管理者', '系統管理員', '管理總監'], true)
        || mb_strpos($role, '管理') !== false;
}

function baohui_can_enter_one_dollar(): bool
{
    if (!empty($_SESSION['baohui_is_admin']) || !empty($_SESSION['is_admin'])) return true;
    if (!baohui_session_logged_in()) return false;

    $user = baohui_session_user_name();
    $role = baohui_session_role();
    if (in_array($role, ['最高管理員', '管理者', '系統管理員', '管理總監'], true)) return true;
    if (in_array(strtolower($user), ['admin', 'administrator', 'root'], true)) return true;
    if (in_array($user, ['管理者', '最高管理員'], true)) return true;

    $data = baohui_main_data();
    $perms = $data['permissions'][$user] ?? [];
    if (!is_array($perms)) return false;
    foreach (['*', 'oneDollarAdmin', 'oneDollar', 'ecommerce', 'auction', 'products'] as $key) {
        if (in_array($key, $perms, true)) return true;
    }
    return false;
}

function baohui_is_embed_request(): bool
{
    return (($_GET['embed'] ?? '') === '1')
        || (stripos((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''), 'iframe') !== false);
}

function baohui_bridge_one_dollar_user(): void {
    // 張張獨立登入：已有 one-dollar-auction 的 $_SESSION['user']、且不是寶輝總部 session 時，不要改寫也不要踢去權限頁
    if (!empty($_SESSION['user']) && is_array($_SESSION['user']) && empty($_SESSION['baohui_logged_in'])) {
        return;
    }
    if (!baohui_session_logged_in()) return;
    if (!baohui_can_enter_one_dollar()) {
        http_response_code(403);
        $embedNote = baohui_is_embed_request()
            ? '<p>請留在目前寶輝後台，到「權限設定」勾選電商營運管理後再回來。不要另開視窗。</p>'
            : '<p><a href="/admin.php">回寶輝總部後台</a></p>';
        echo '<!doctype html><meta charset="utf-8"><div style="font-family:Arial, sans-serif;padding:32px;color:#0f172a"><h2>沒有電商營運管理權限</h2><p>請到寶輝總部管理系統的「權限設定」勾選電商營運管理。</p>' . $embedNote . '</div>';
        exit;
    }
    $user = baohui_session_user_name();
    $role = baohui_session_role();
    $isAdmin = !empty($_SESSION['baohui_is_admin']) || !empty($_SESSION['is_admin']) || in_array($role, ['最高管理員', '管理者', '系統管理員'], true);
    $_SESSION['user'] = [
        'id' => $isAdmin ? 'baohui_admin' : ('baohui_' . md5($user)),
        'name' => $user,
        'account' => $user,
        'role' => $isAdmin ? '最高管理員' : ($role !== '' ? $role : '管理總監'),
        'must_change_password' => false,
        'source' => 'baohui_admin',
    ];
}

baohui_bridge_one_dollar_user();
date_default_timezone_set('Asia/Taipei');
$isEmbed = baohui_is_embed_request();
if (empty($_SESSION['user'])) {
    if ($isEmbed) {
            http_response_code(401);
            echo '<!doctype html><meta charset="utf-8"><div style="font-family:Arial, sans-serif;padding:32px;color:#0f172a"><h2>請先登入張張管理後台</h2><p>請到 <a href="/one-dollar-auction/">張張登入頁</a> 登入後再進入。也可從寶輝總部左側「電商營運管理」進入。</p></div>';
            exit;
        }
    header('Location: /one-dollar-auction/');
    exit;
}

$dataDir = __DIR__ . '/data';
$productUploadDir = __DIR__ . '/uploads/products';
$scheduleUploadDir = __DIR__ . '/uploads/schedules';
foreach ([$dataDir, $productUploadDir, $scheduleUploadDir] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0775, true);
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function current_operator() {
    $u = $_SESSION['user'] ?? '';
    if (is_array($u)) {
        foreach (['name', 'username', 'account', 'employee_name', 'id'] as $k) {
            if (!empty($u[$k])) return (string)$u[$k];
        }
        return json_encode($u, JSON_UNESCAPED_UNICODE);
    }
    return trim((string)$u) !== '' ? trim((string)$u) : 'unknown';
}
function money($v) { return '$' . number_format((float)$v, 0); }
function data_path($name) { global $dataDir; return $dataDir . '/' . $name . '.json'; }
function json_flags($flags = 0) {
    return $flags | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
}
function read_data($name) {
    $file = data_path($name);
    if (!file_exists($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') return [];
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    $data = json_decode($raw, true, 512, json_flags());
    return is_array($data) ? $data : [];
}
function read_json_object($name) {
    $file = data_path($name);
    if (!file_exists($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') return [];
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    $data = json_decode($raw, true, 512, json_flags());
    return is_array($data) ? $data : [];
}
function write_data($name, $data) {
    file_put_contents(data_path($name), json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}
require_once __DIR__ . DIRECTORY_SEPARATOR . 'marketplace-channels.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'post-reply-sets.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'facebook-daily-report.php';
function uid($prefix) { return $prefix . date('ymdHis') . substr(bin2hex(random_bytes(3)), 0, 6); }
function pinduoduo_taobao_supplier_name(): string
{
    return '拼多多/淘寶';
}
function is_pinduoduo_taobao_supplier_name(string $name): bool
{
    $name = trim($name);
    if ($name === '') return false;
    $aliases = ['拼多多/淘寶', '拚多多/掏寶', '拼多多/掏寶', '拚多多/淘寶', '拼多多', '淘寶'];
    if (in_array($name, $aliases, true)) return true;
    $hasPdd = (function_exists('mb_strpos') ? mb_strpos($name, '拼多多') : strpos($name, '拼多多')) !== false
        || (function_exists('mb_strpos') ? mb_strpos($name, '拚多多') : strpos($name, '拚多多')) !== false;
    $hasTb = (function_exists('mb_strpos') ? mb_strpos($name, '淘寶') : strpos($name, '淘寶')) !== false
        || (function_exists('mb_strpos') ? mb_strpos($name, '掏寶') : strpos($name, '掏寶')) !== false;
    return $hasPdd && $hasTb;
}
function ensure_pinduoduo_taobao_supplier(array &$suppliers): string
{
    $bestId = '';
    $bestScore = 0;
    foreach ($suppliers as $sp) {
        $name = trim((string)($sp['name'] ?? ''));
        $score = 0;
        if (in_array($name, ['拼多多/淘寶', '拚多多/掏寶', '拼多多/掏寶', '拚多多/淘寶'], true)) $score = 3;
        elseif (is_pinduoduo_taobao_supplier_name($name) && $name !== '拼多多' && $name !== '淘寶') $score = 2;
        elseif ($name === '拼多多' || $name === '淘寶') $score = 1;
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestId = (string)($sp['id'] ?? '');
        }
    }
    if ($bestId !== '') return $bestId;
    $row = [
        'id' => uid('sup_'),
        'name' => pinduoduo_taobao_supplier_name(),
        'contact' => '',
        'phone' => '',
        'tax_id' => '',
        'address' => '',
        'note' => '進貨單據預設廠商',
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'operator' => current_operator(),
    ];
    $suppliers[] = $row;
    write_data('suppliers', $suppliers);
    return $row['id'];
}
function supplier_name_key($value) {
    return mb_strtolower(trim((string)$value), 'UTF-8');
}
function find_supplier_by_name(array $suppliers, string $name): ?array
{
    $name = trim($name);
    if ($name === '') return null;
    if (is_pinduoduo_taobao_supplier_name($name)) {
        foreach ($suppliers as $sp) {
            if (is_pinduoduo_taobao_supplier_name((string)($sp['name'] ?? ''))) return $sp;
        }
    }
    $key = supplier_name_key($name);
    foreach ($suppliers as $sp) {
        if (supplier_name_key((string)($sp['name'] ?? '')) === $key) return $sp;
    }
    return null;
}
function ensure_named_supplier(array &$suppliers, string $name, string $note = '產品建檔預設供應來源'): ?array
{
    $name = trim($name);
    if ($name === '' || $name === '其他') return null;
    if (is_pinduoduo_taobao_supplier_name($name)) {
        ensure_pinduoduo_taobao_supplier($suppliers);
        return find_supplier_by_name($suppliers, $name);
    }
    $existing = find_supplier_by_name($suppliers, $name);
    if ($existing) {
        if (trim((string)($existing['source_category'] ?? '')) === '') {
            foreach ($suppliers as &$sp) {
                if (($sp['id'] ?? '') === ($existing['id'] ?? '')) {
                    $sp['source_category'] = '產品建檔供應來源';
                    $sp['updated_at'] = date('c');
                    $existing = $sp;
                    break;
                }
            }
            unset($sp);
            write_data('suppliers', $suppliers);
        }
        return $existing;
    }
    $row = [
        'id' => uid('sup_'),
        'name' => $name,
        'contact' => '',
        'phone' => '',
        'tax_id' => '',
        'address' => '',
        'note' => $note,
        'source_category' => '產品建檔供應來源',
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'operator' => current_operator(),
    ];
    $suppliers[] = $row;
    write_data('suppliers', $suppliers);
    return $row;
}
function purchase_source_options(array $suppliers, array $products): array
{
    $out = [];
    foreach (['其他', '拼多多', '豪鴻'] as $fixed) $out[supplier_name_key($fixed)] = $fixed;
    foreach ($suppliers as $sp) {
        $name = trim((string)($sp['name'] ?? ''));
        if ($name === '') continue;
        $out[supplier_name_key($name)] = $name;
    }
    foreach ($products as $p) {
        if (!is_array($p)) continue;
        $name = trim((string)($p['purchase_source'] ?? ''));
        if ($name === '') continue;
        $out[supplier_name_key($name)] = $name;
    }
    $list = array_values($out);
    usort($list, function ($a, $b) {
        $rank = static function ($value) {
            if ($value === '其他') return 0;
            if (in_array($value, ['拼多多', '豪鴻'], true)) return 1;
            return 2;
        };
        $ra = $rank($a);
        $rb = $rank($b);
        return $ra === $rb ? strnatcasecmp($a, $b) : ($ra <=> $rb);
    });
    return $list;
}
function supplier_search_category(array $supplier): string
{
    $category = trim((string)($supplier['source_category'] ?? ''));
    if ($category !== '') return $category;
    $note = trim((string)($supplier['note'] ?? ''));
    if ($note === '產品建檔預設供應來源' || $note === '進貨單據預設廠商') return '產品建檔供應來源';
    return '廠商建檔';
}
function spec_option_key($value) {
    return mb_strtolower(trim((string)$value), 'UTF-8');
}
function normalize_spec_options($items) {
    $out = [];
    foreach ((array)$items as $item) {
        $value = trim(is_array($item) ? (string)($item['name'] ?? $item['value'] ?? $item['spec'] ?? '') : (string)$item);
        if ($value === '') continue;
        $key = spec_option_key($value);
        if ($key === '' || isset($out[$key])) continue;
        $out[$key] = $value;
    }
    $list = array_values($out);
    natcasesort($list);
    return array_values($list);
}
function remember_product_spec(&$specs, $value) {
    $value = trim((string)$value);
    if ($value === '') return false;
    $key = spec_option_key($value);
    foreach ($specs as $existing) {
        if (spec_option_key($existing) === $key) return false;
    }
    $specs[] = $value;
    $specs = normalize_spec_options($specs);
    return true;
}
function lingzanzan_root_path() {
    $candidates = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lingzanzan-staging',
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'lingzanzan-staging',
    ];
    foreach ($candidates as $root) {
        $resolved = realpath($root);
        if ($resolved && is_dir($resolved)) return $resolved;
    }
    return '';
}
function sync_lingzanzan_computer_type($type, $typeCode = '') {
    $type = trim((string)$type);
    if ($type === '') return false;
    $root = lingzanzan_root_path();
    if ($root === '') return false;
    $file = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'admin-state.json';
    if (!is_file($file)) return false;
    $raw = file_get_contents($file);
    $state = json_decode($raw, true);
    if (!is_array($state)) return false;
    $list = [];
    foreach (['computerCategories', 'categories'] as $key) {
        foreach ((array)($state[$key] ?? []) as $name) {
            $name = trim((string)$name);
            if ($name !== '') $list[$name] = $name;
        }
    }
    if (isset($list[$type])) return false;
    $list[$type] = $type;
    $state['computerCategories'] = array_values($list);
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    return file_put_contents($file, $json, LOCK_EX) !== false;
}
function ensure_product_category_rule(&$categories, $group, $type, $brand = '', $spec = '') {
    $group = trim((string)$group);
    $type = trim((string)$type);
    $brand = trim((string)$brand);
    $spec = trim((string)$spec);
    if ($group === '') $group = function_exists('product_category_root_groups') ? '組裝硬體' : '電腦部門';
    if ($type === '') return null;
    $existing = find_product_category_rule($categories, $group, $type, $brand, $spec);
    if ($existing) return $existing;
    $typeCode = category_type_code($type);
    $row = [
        'id' => uid('pc_'),
        'group' => $group,
        'type' => $type,
        'brand' => $brand,
        'spec' => $spec,
        'type_code' => $typeCode,
        'barcode_prefix' => $typeCode,
        'sort' => 0,
        'note' => '建檔手打新增',
        'department' => $group,
        'created_at' => date('c'),
        'updated_at' => date('c'),
    ];
    $categories[] = $row;
    write_data('product_categories', $categories);
    if (in_array($group, ['電腦部門', '電腦', '組裝硬體'], true)) sync_lingzanzan_computer_type($type, $typeCode);
    return $row;
}
function uploaded_image_ext($name, $mime = '') {
    $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    if (in_array($ext, ['jpg', 'png', 'webp'], true)) return $ext;
    $mime = strtolower(trim((string)$mime));
    if (in_array($mime, ['image/jpeg', 'image/pjpeg', 'image/jpg'], true)) return 'jpg';
    if (in_array($mime, ['image/png', 'image/x-png'], true)) return 'png';
    if ($mime === 'image/webp') return 'webp';
    return '';
}
function upload_image($field, $prefix, $folder) {
    if (empty($_FILES[$field]['name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) return '';
    $ext = uploaded_image_ext($_FILES[$field]['name'], $_FILES[$field]['type'] ?? '');
    if ($ext === '') return '';
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $prefix);
    $relative = $folder . '/' . $safe . '-' . time() . '.' . $ext;
    return move_uploaded_file($_FILES[$field]['tmp_name'], __DIR__ . '/' . $relative) ? $relative : '';
}
function upload_images($field, $prefix, $folder) {
    if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) return [];
    $saved = [];
    foreach ($_FILES[$field]['name'] as $i => $name) {
        if (!$name || empty($_FILES[$field]['tmp_name'][$i]) || !is_uploaded_file($_FILES[$field]['tmp_name'][$i])) continue;
        $ext = uploaded_image_ext($name, $_FILES[$field]['type'][$i] ?? '');
        if ($ext === '') continue;
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $prefix);
        $relative = $folder . '/' . $safe . '-' . time() . '-' . $i . '.' . $ext;
        if (move_uploaded_file($_FILES[$field]['tmp_name'][$i], __DIR__ . '/' . $relative)) $saved[] = $relative;
    }
    return $saved;
}
function apply_product_uploaded_images(array &$product, $prefix, $mainField, $extraField) {
    $changed = false;
    $main = upload_image($mainField, $prefix . '-main', 'uploads/products');
    $extra = upload_images($extraField, $prefix . '-detail', 'uploads/products');
    if ($main !== '') {
        $product['image'] = $main;
        $changed = true;
    }
    if ($extra) {
        $current = $product['extra_images'] ?? [];
        if (!is_array($current)) $current = [];
        $product['extra_images'] = array_values(array_unique(array_merge($current, $extra)));
        $changed = true;
    }
    if ($changed) $product['updated_at'] = date('c');
    return $changed;
}
function render_photo_capture_fields($mainName, $extraField, $options = []) {
    $class = trim('photo-capture-box ' . ($options['class'] ?? 'wide'));
    $title = $options['title'] ?? '拍照 / 上傳圖片';
    $hint = $options['hint'] ?? '可拍照、選相簿，或直接 Ctrl+V 貼上圖片。主圖限 1 張，細圖可連續加拍後再儲存。';
    $mainLabel = $options['main_label'] ?? '主圖（1 張，會取代）';
    $extraLabel = $options['extra_label'] ?? '其他細圖（可多張追加）';
    $extraName = $extraField . '[]';
    ?>
    <div class="<?=h($class)?>">
      <b><?=h($title)?></b>
      <p class="muted"><?=h($hint)?></p>
      <div class="photo-capture-grid">
        <div class="photo-capture-col">
          <span><?=h($mainLabel)?></span>
          <div class="photo-capture-actions">
            <label class="photo-btn camera">拍照主圖<input type="file" accept="image/*" capture="environment" data-photo-assign="<?=h($mainName)?>"></label>
            <label class="photo-btn album">相簿選主圖<input type="file" accept="image/*" data-photo-assign="<?=h($mainName)?>"></label>
            <button class="photo-btn paste" type="button" data-photo-paste="<?=h($mainName)?>">貼上主圖</button>
          </div>
          <input class="photo-native-input" name="<?=h($mainName)?>" type="file" accept="image/*" hidden>
        </div>
        <div class="photo-capture-col">
          <span><?=h($extraLabel)?></span>
          <div class="photo-capture-actions">
            <label class="photo-btn camera">拍照加細圖<input type="file" accept="image/*" capture="environment" data-photo-assign="<?=h($extraName)?>" data-photo-append="1"></label>
            <label class="photo-btn album">相簿選細圖<input type="file" accept="image/*" multiple data-photo-assign="<?=h($extraName)?>" data-photo-append="1"></label>
            <button class="photo-btn paste" type="button" data-photo-paste="<?=h($extraName)?>" data-photo-append="1">貼上細圖</button>
          </div>
          <input class="photo-native-input" name="<?=h($extraName)?>" type="file" accept="image/*" multiple hidden>
        </div>
      </div>
      <div class="photo-inline-preview" data-photo-preview hidden></div>
    </div>
    <?php
}
function product_label($p) {
    $parts = array_filter([
        $p['id'] ?? '',
        $p['barcode'] ?? '',
        $p['title'] ?? '',
        $p['color'] ?? '',
        $p['size'] ?? '',
        $p['warehouse_name'] ?? '',
        $p['shelf_code'] ?? '',
        $p['warehouse_location'] ?? ''
    ], function($v) { return trim((string)$v) !== ''; });
    return implode(' / ', $parts);
}
function find_product_key($products, $key) {
    $key = strtoupper(trim((string)$key));
    if ($key === '') return -1;
    foreach ($products as $i => $p) {
        $id = strtoupper(trim((string)($p['id'] ?? '')));
        $barcode = strtoupper(trim((string)($p['barcode'] ?? '')));
        $serial = strtoupper(product_serial_base($p));
        $printed = strtoupper(latest_cost_barcode($p));
        if ($id === $key || $barcode === $key || $serial === $key || $printed === $key) return $i;
    }
    return -1;
}

function next_product_id($products, $prefix = 'P') {
    $max = 0;
    foreach ($products as $p) {
        $id = (string)($p['id'] ?? '');
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $id, $m)) {
            $max = max($max, (int)$m[1]);
        }
    }
    return $prefix . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
}
function normalize_barcode_prefix($value) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', trim((string)$value)));
}
function next_product_barcode($products, $prefix) {
    $prefix = normalize_barcode_prefix($prefix);
    if ($prefix === '') return '';
    $max = 0;
    $width = 6;
    foreach ($products as $product) {
        $barcode = strtoupper(trim((string)($product['barcode'] ?? '')));
        if (!preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $barcode, $match)) continue;
        $max = max($max, (int)$match[1]);
        $width = max($width, strlen($match[1]));
    }
    return $prefix . str_pad((string)($max + 1), $width, '0', STR_PAD_LEFT);
}
function category_type_code_map() {
    return [
        '主機' => 'COM', '電腦主機' => 'COM', 'COMPUTER' => 'COM', 'Computer' => 'COM',
        '主機板' => 'MB', '顯示卡' => 'GPU', '處理器' => 'CPU', '記憶體' => 'RAM',
        '硬碟SSD' => 'SSD', '硬碟外接盒' => 'ENC', '電源供應器' => 'PSU', '電腦機箱' => 'CASE',
        '散熱設備' => 'FAN', '電腦螢幕' => 'MON', '筆電' => 'NB', '網路設備' => 'NET',
        '印表機' => 'PRT', '電腦周邊' => 'ACC', '3C周邊' => 'C3C', '生活周邊' => 'LIFE', '鍵盤與滑鼠' => 'KB',
        '軟體專區' => 'SW', '線材類(網路頭）' => 'CBL', '線材類' => 'CBL', '消耗品' => 'CON',
        '消耗性產品' => 'CON', '檢測設備' => 'TST', '健康設備' => 'HLT', '光碟機燒錄機' => 'ODD',
        '分享設備' => 'SHR', '切換器' => 'SWH', '回收(大陸)' => 'REC', '套裝款設備' => 'KIT',
        '服飾專區' => 'CLO', '標籤雷射貼紙' => 'LBL', '機箱燈條' => 'LED', '燈飾專區' => 'LGT',
        '福利標' => 'BGO', '讀卡機' => 'RDR', '轉換設備' => 'ADP', '隨身碟' => 'USB',
        '電子清潔用品' => 'CLN', '音響' => 'AUD', '麥克風' => 'MIC', '褲子' => 'PNT', '上衣' => 'TOP',
        '筆記型電腦' => 'NB', '桌上型電腦' => 'PC', '一體式電腦' => 'AIO', '伺服器／工作站' => 'WS',
        '螢幕' => 'MON', 'CPU處理器' => 'CPU', '儲存裝置' => 'SSD', '機殼' => 'CASE', '散熱器' => 'FAN',
        '鍵盤／滑鼠' => 'KB', '音訊／視訊設備' => 'AV', '線材／轉接器' => 'CBL',
        '電腦零組件' => 'PART', '周邊設備' => 'PER', '生活周邊' => 'LIFE',
        '其他電腦零組件' => 'PART', '其他電腦周邊' => 'PER', '其他電腦商品' => 'ITEM', '電腦商品' => 'ITEM',
    ];
    if (function_exists('product_category_extra_type_codes')) {
        $map = array_merge($map, product_category_extra_type_codes());
    }
    return $map;
}
function category_type_code($type, $explicit = '', $barcodePrefix = '') {
    $explicit = normalize_barcode_prefix($explicit);
    if ($explicit !== '') return $explicit;
    $type = trim((string)$type);
    $map = category_type_code_map();
    if ($type !== '' && isset($map[$type])) return $map[$type];
    if (preg_match('/[A-Za-z]{2,}/', $type, $match)) return strtoupper(substr($match[0], 0, 3));
    $prefix = normalize_barcode_prefix($barcodePrefix);
    if (preg_match('/^[A-Z]{2,4}/', $prefix, $match)) return $match[0];
    return $type !== '' ? 'ITEM' : '';
}
function product_serial_base($product) {
    $base = normalize_barcode_prefix($product['original_product_code'] ?? '');
    if ($base !== '') return $base;
    $barcode = strtoupper(trim((string)($product['barcode'] ?? '')));
    if ($barcode !== '' && preg_match('/^(.+?)P[0-9]/', $barcode, $match)) return normalize_barcode_prefix($match[1]);
    $id = strtoupper(trim((string)($product['id'] ?? '')));
    if ($id !== '' && preg_match('/^(.+?)P[0-9]/', $id, $match)) return normalize_barcode_prefix($match[1]);
    return normalize_barcode_prefix($id);
}
function next_type_serial($products, $code, $width = 3) {
    $code = normalize_barcode_prefix($code);
    if ($code === '') return '';
    $max = 0;
    foreach ($products as $product) {
        $base = product_serial_base($product);
        if (!preg_match('/^' . preg_quote($code, '/') . '(\d+)$/', $base, $match)) continue;
        $max = max($max, (int)$match[1]);
    }
    $next = $max + 1;
    return $code . str_pad((string)$next, max($width, strlen((string)$next)), '0', STR_PAD_LEFT);
}
function first_product_code($value) {
    $parts = preg_split('/[、,，\s]+/u', trim((string)$value));
    foreach ($parts as $part) {
        $part = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim((string)$part)));
        if ($part !== '') return $part;
    }
    return '';
}
function build_product_cost_barcode($base, $cost, $colorCode = '', $sizeCode = '') {
    $base = normalize_barcode_prefix($base);
    if ($base === '') return '';
    $costPart = (string)max(0, (int)round((float)$cost));
    $colorCode = first_product_code($colorCode);
    return $base . 'P' . $costPart . $colorCode;
}
function product_barcode_sort_key($product) {
    $code = strtoupper(trim((string)($product['barcode'] ?? $product['id'] ?? '')));
    if (preg_match('/^([A-Z]+)(\d*)P(\d+)([A-Z0-9]*)$/', $code, $match)) {
        return sprintf('%s-%010d-P-%010d-%s', $match[1], (int)$match[2], (int)$match[3], $match[4]);
    }
    return $code;
}
function find_product_category_rule($categories, $group, $type, $brand, $spec) {
    $group = trim((string)$group);
    $type = trim((string)$type);
    $brand = trim((string)$brand);
    $spec = trim((string)$spec);
    foreach ($categories as $category) {
        if (trim((string)($category['group'] ?? '')) !== $group) continue;
        if (trim((string)($category['type'] ?? '')) !== $type) continue;
        if (trim((string)($category['brand'] ?? '')) !== $brand) continue;
        if (trim((string)($category['spec'] ?? '')) !== $spec) continue;
        return $category;
    }
    return null;
}
function normalize_import_header($v) {
    $v = trim((string)$v);
    if (function_exists('mb_strtolower')) $v = mb_strtolower($v, 'UTF-8');
    else $v = strtolower($v);
    return preg_replace('/\s+/u', '', $v);
}
function row_value($row, $aliases) {
    foreach ($aliases as $name) {
        $key = normalize_import_header($name);
        if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }
    return '';
}
function number_value($v) {
    $v = preg_replace('/[^0-9.\-]+/', '', (string)$v);
    return $v === '' ? 0 : (float)$v;
}
function parse_csv_rows($csv) {
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', (string)$csv);
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $csv);
    rewind($fp);
    $headers = fgetcsv($fp);
    if (!$headers) return [];
    $headers = array_map('normalize_import_header', $headers);
    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        $empty = true;
        $row = [];
        foreach ($headers as $i => $h) {
            $value = $cols[$i] ?? '';
            if (trim((string)$value) !== '') $empty = false;
            $row[$h] = $value;
        }
        if (!$empty) $rows[] = $row;
    }
    fclose($fp);
    return $rows;
}
function google_sheet_csv_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (strpos($url, '/export?') !== false || strpos($url, 'output=csv') !== false) return $url;
    if (preg_match('~/spreadsheets/d/([^/]+)~', $url, $m)) {
        $gid = '0';
        if (preg_match('~[?&#]gid=([0-9]+)~', $url, $gm)) $gid = $gm[1];
        return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv&gid=' . $gid;
    }
    return $url;
}
function fetch_csv_text($url) {
    $url = google_sheet_csv_url($url);
    if ($url === '') return '';
    $context = stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 1]]);
    $csv = @file_get_contents($url, false, $context);
    if ($csv !== false && trim((string)$csv) !== '') return $csv;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $csv = curl_exec($ch);
        curl_close($ch);
        if ($csv !== false) return $csv;
    }
    return '';
}
function import_product_rows($rows, $products) {
    $count = 0;
    foreach ($rows as $row) {
        $id = row_value($row, ['產品編號','商品編號','編號','SKU','sku','id','貨號']);
        $barcode = row_value($row, ['產品條碼','商品條碼','條碼','barcode','ean']);
        if ($id === '' && $barcode === '') continue;
        if ($id === '') $id = $barcode;
        $idx = find_product_key($products, $id);
        if ($idx < 0 && $barcode !== '') $idx = find_product_key($products, $barcode);
        $existing = $idx >= 0 ? $products[$idx] : [];

        $title = row_value($row, ['產品名稱','商品名稱','商品標題','名稱','品名','title','name']);
        $stockTotal = row_value($row, ['上架後數量','最後統計數量','統計數量','庫存數量','總庫存','stock_total','stock']);
        $sold = row_value($row, ['已售出','售出','已售數量','sold','stock_sold']);
        $cost = row_value($row, ['成本','成本價','cost']);
        $startPrice = row_value($row, ['起標價','start_price']);
        $bidStep = row_value($row, ['加價幅度','bid_step']);

        $data = array_merge($existing, [
            'id' => $id,
            'barcode' => $barcode ?: ($existing['barcode'] ?? ''),
            'title' => $title ?: ($existing['title'] ?? ''),
            'product_name' => $title ?: ($existing['product_name'] ?? ($existing['title'] ?? '')),
            'color' => row_value($row, ['顏色','色號','color']) ?: ($existing['color'] ?? ''),
            'size' => row_value($row, ['尺碼','尺寸','size']) ?: ($existing['size'] ?? ''),
            'spec' => row_value($row, ['規格','規格尺寸','spec']) ?: ($existing['spec'] ?? ''),
            'warehouse_name' => row_value($row, ['倉庫','倉別','庫房','warehouse_name','warehouse']) ?: ($existing['warehouse_name'] ?? ''),
            'shelf_code' => row_value($row, ['貨架','架位','層架','shelf','rack','shelf_code']) ?: ($existing['shelf_code'] ?? ''),
            'warehouse_location' => row_value($row, ['倉位','庫位','儲位','位置','location']) ?: ($existing['warehouse_location'] ?? ''),
            'description_source' => row_value($row, ['原始描述','產品描述','來源描述','description_source']) ?: ($existing['description_source'] ?? ''),
            'description' => row_value($row, ['整理描述','AI描述','內容','商品內容','備註','description']) ?: ($existing['description'] ?? ''),
            'cost' => $cost !== '' ? number_value($cost) : (float)($existing['cost'] ?? 0),
            'stock_total' => $stockTotal !== '' ? (int)number_value($stockTotal) : (int)($existing['stock_total'] ?? 0),
            'stock_reserved' => (int)($existing['stock_reserved'] ?? 0),
            'stock_sold' => $sold !== '' ? (int)number_value($sold) : (int)($existing['stock_sold'] ?? 0),
            'start_price' => $startPrice !== '' ? number_value($startPrice) : (float)($existing['start_price'] ?? 1),
            'bid_step' => $bidStep !== '' ? number_value($bidStep) : (float)($existing['bid_step'] ?? 10),
            'image' => $existing['image'] ?? '',
            'extra_images' => $existing['extra_images'] ?? [],
            'status' => row_value($row, ['新上架','狀態','status']) ?: ($existing['status'] ?? '可排程'),
            'created_at' => $existing['created_at'] ?? date('c'),
            'updated_at' => date('c'),
            'imported_at' => date('c'),
        ]);
        if ($idx >= 0) $products[$idx] = $data;
        else $products[] = $data;
        $count++;
    }
    return [$products, $count];
}

function product_by_id($products, $id) {
    foreach ($products as $p) if (($p['id'] ?? '') === $id) return $p;
    return [];
}
function product_by_key($products, $key) {
    $idx = find_product_key($products, $key);
    return $idx >= 0 ? $products[$idx] : [];
}
function product_in_schedules($schedules, $productId) {
    foreach ($schedules as $s) {
        if (($s['product_id'] ?? '') === $productId) return true;
    }
    return false;
}
function product_images($product) {
    $images = [];
    if (!empty($product['image'])) $images[] = $product['image'];
    foreach (($product['extra_images'] ?? []) as $img) if ($img && !in_array($img, $images, true)) $images[] = $img;
    return $images;
}
function schedule_by_id($schedules, $id) {
    foreach ($schedules as $s) if (($s['id'] ?? '') === $id) return $s;
    return [];
}
function totals($s, $p = []) {
    $qty = max(1, (int)($s['quantity'] ?? 1));
    $win = (float)($s['winning_price'] ?? 0);
    $base = $win * $qty;
    $taxIncluded = !empty($s['tax_included']) && (string)$s['tax_included'] !== '0';
    $tax = $taxIncluded ? round($base * 0.05) : 0;
    $feeValue = (float)($s['fee_value'] ?? 0);
    $manualFee = (($s['fee_type'] ?? 'amount') === 'percent') ? round($base * $feeValue / 100) : $feeValue;
    $fee = $taxIncluded ? $tax : $manualFee;
    $shipping = (float)($s['shipping_fee'] ?? 0);
    $other = (float)($s['other_fee'] ?? 0);
    $receivable = $base + $fee + $shipping + $other;
    $cost = (float)($s['product_cost'] ?? ($p['cost'] ?? 0)) * $qty;
    $paid = (float)($s['paid_amount'] ?? 0);
    return ['base' => $base, 'tax' => $tax, 'fee' => $fee, 'receivable' => $receivable, 'cost' => $cost, 'profit' => $receivable - $cost, 'paid' => $paid, 'unpaid' => max(0, $receivable - $paid)];
}
function winner_notice_text($s, $p, $t) {
    $date = substr((string)($s['close_at'] ?? $s['publish_at'] ?? date('Y-m-d')), 0, 10);
    $taxText = (!empty($s['tax_included']) && (string)$s['tax_included'] !== '0') ? '含稅 5%：' . money($t['tax']) : '未稅：$0';
    $logistics = trim(($s['logistics_company'] ?? '') . ' ' . ($s['tracking_no'] ?? ''));
    return trim("得標通知
"
        . "得標者：" . ($s['winner'] ?? '') . "
"
        . "購買日期：" . $date . "
"
        . "產品編號：" . ($s['product_id'] ?? ($p['id'] ?? '')) . "
"
        . "公司條碼：" . ($s['product_barcode'] ?? ($p['barcode'] ?? '')) . "
"
        . "產品序號/保固序號：" . ($s['product_serial'] ?? ($s['warranty_serial'] ?? '')) . "
"
        . "產品名稱：" . ($p['title'] ?? ($s['product_title'] ?? '')) . "
"
        . "顏色/尺寸/規格：" . trim(($p['color'] ?? ($s['product_color'] ?? '')) . ' / ' . ($p['size'] ?? ($s['product_size'] ?? '')) . ' / ' . ($p['spec'] ?? ($s['product_spec'] ?? '')), ' /') . "
"
        . "數量：" . max(1, (int)($s['quantity'] ?? 1)) . "
"
        . "得標金額：" . money($s['winning_price'] ?? 0) . "
"
        . "稅金：" . $taxText . "
"
        . "運費：" . money($s['shipping_fee'] ?? 0) . "
"
        . "應收合計：" . money($t['receivable']) . "
"
        . "已收：" . money($t['paid']) . "
"
        . "未收：" . money($t['unpaid']) . "
"
        . "付款狀態：" . ($s['payment_status'] ?? '未付款') . "
"
        . "出貨狀態：" . ($s['shipping_status'] ?? '未出貨') . "
"
        . "物流：" . $logistics);
}
function auction_notice_text($s, $p, $t) {
    return trim("競標結標通知
"
        . "產品編號：" . ($s['product_id'] ?? ($p['id'] ?? '')) . "
"
        . "公司條碼：" . ($s['product_barcode'] ?? ($p['barcode'] ?? '')) . "
"
        . "產品序號/保固序號：" . ($s['product_serial'] ?? ($s['warranty_serial'] ?? '')) . "
"
        . "產品名稱：" . ($p['title'] ?? ($s['product_title'] ?? '')) . "
"
        . "得標者：" . ($s['winner'] ?? '') . "
"
        . "得標金額：" . money($s['winning_price'] ?? 0) . "
"
        . "結標時間：" . ($s['close_at'] ?? '') . "
"
        . "目前狀態：" . ($s['order_status'] ?? '待記單'));
}
function safe_http_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (!preg_match('~^https?://~i', $url)) return '';
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}
function public_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'baohui.paohui.org';
    return $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/one-dollar-auction/operations.php'), '/\\');
}
function ensure_order_token(&$s) {
    if (trim((string)($s['order_token'] ?? '')) === '') $s['order_token'] = bin2hex(random_bytes(16));
    return $s['order_token'];
}
function buyer_order_url($s) {
    $token = trim((string)($s['order_token'] ?? ''));
    if ($token === '') return '';
    return public_base_url() . '/buyer-order.php?t=' . rawurlencode($token);
}
function auction_status_label($s) {
    $now = time();
    $start = strtotime((string)($s['publish_at'] ?? $s['scheduled_publish_at'] ?? '')) ?: 0;
    $close = strtotime((string)($s['close_at'] ?? $s['close_remind_at'] ?? '')) ?: 0;
    if ($close && $now >= $close) return '已截標';
    if ($start && $now < $start) return '即將開始';
    return '競標中';
}
function reminder_next_time($s) {
    $base = trim((string)($s['last_reminder_at'] ?? '')) ?: trim((string)($s['bid_updated_at'] ?? '')) ?: trim((string)($s['publish_at'] ?? ''));
    $ts = $base !== '' ? strtotime($base) : false;
    if (!$ts) $ts = time();
    return date('Y-m-d H:i', $ts + 3600);
}
function facebook_listing_draft_text($s, $p, $setId = '') {
    global $postReplySets;
    $sets = (isset($postReplySets) && is_array($postReplySets) && $postReplySets)
        ? $postReplySets
        : normalize_post_reply_sets(default_post_reply_sets());
    return render_post_reply_listing(is_array($s) ? $s : [], is_array($p) ? $p : [], $sets, (string)$setId);
}
function reminder_draft_text($s, $p) {
    $url = safe_http_url($s['post_url'] ?? '');
    $bid = (float)($s['current_bid'] ?? $s['winning_price'] ?? 0);
    $lines = [];
    $lines[] = '【一元競標提醒】';
    $lines[] = '商品：' . (($p['title'] ?? '') ?: ($s['product_title'] ?? ''));
    $lines[] = '產品編號：' . ($s['product_id'] ?? ($p['id'] ?? ''));
    $barcode = trim((string)($s['product_barcode'] ?? ($p['barcode'] ?? '')));
    if ($barcode !== '') $lines[] = '公司條碼：' . $barcode;
    $spec = trim((string)(($s['product_color'] ?? ($p['color'] ?? '')) . ' / ' . ($s['product_size'] ?? ($p['size'] ?? '')) . ' / ' . ($s['product_spec'] ?? ($p['spec'] ?? ''))), ' /');
    if ($spec !== '') $lines[] = '規格：' . $spec;
    $lines[] = '目前競標金額：' . money($bid);
    $lines[] = '截標時間：' . ($s['close_at'] ?? '');
    $lines[] = $url !== '' ? '競標貼文：' . $url : '競標貼文：尚未提供貼文連結';
    $lines[] = '提醒狀態：草稿待人工確認發布';
    return implode("\n", $lines);
}
function schedule_needs_hourly_update($s) {
    $last = trim((string)($s['bid_updated_at'] ?? $s['last_reminder_at'] ?? ''));
    $ts = $last !== '' ? strtotime($last) : false;
    return !$ts || (time() - $ts >= 3600);
}

function buyer_key($row) {
    foreach (['phone', 'facebook', 'name'] as $k) {
        $v = trim((string)($row[$k] ?? ''));
        if ($v !== '') return $k . ':' . mb_strtolower($v, 'UTF-8');
    }
    return '';
}
function find_member_index($members, $buyer) {
    $keys = [];
    foreach (['phone', 'facebook', 'name'] as $k) {
        $v = trim((string)($buyer[$k] ?? ''));
        if ($v !== '') $keys[] = $k . ':' . mb_strtolower($v, 'UTF-8');
    }
    foreach ($members as $i => $m) {
        foreach ($keys as $key) {
            [$field, $value] = explode(':', $key, 2);
            if (mb_strtolower(trim((string)($m[$field] ?? '')), 'UTF-8') === $value) return $i;
        }
    }
    return -1;
}
function member_contact_fields($member) {
    $row = is_array($member) ? $member : [];
    $name = trim((string)($row['name'] ?? $row['customer_name'] ?? $row['customer'] ?? ''));
    $aliases = [];
    foreach (['name', 'customer_name', 'customer', 'organization_name', 'title'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') $aliases[$value] = $value;
    }
    $address = '';
    foreach (['address', 'addr', 'company_address', 'ship_address'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') { $address = $value; break; }
    }
    $phone = '';
    foreach (['phone', 'tel', 'mobile', 'contact_phone'] as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') { $phone = $value; break; }
    }
    return [
        'name' => $name !== '' ? $name : (array_values($aliases)[0] ?? ''),
        'aliases' => array_values($aliases),
        'facebook' => trim((string)($row['facebook'] ?? '')),
        'phone' => $phone,
        'address' => $address,
        'contact' => trim((string)($row['contact'] ?? '')),
        'email' => trim((string)($row['email'] ?? '')),
        'fax' => trim((string)($row['fax'] ?? '')),
    ];
}
function member_contact_directory($members) {
    $out = [];
    foreach ((array)$members as $member) {
        $row = member_contact_fields($member);
        if ($row['name'] === '' && !$row['aliases']) continue;
        $out[] = $row;
    }
    return $out;
}
function upsert_member_from_schedule($members, $s, $products) {
    $buyer = [
        'name' => trim((string)($s['winner'] ?? '')),
        'facebook' => trim((string)($s['winner_facebook'] ?? '')),
        'phone' => trim((string)($s['winner_phone'] ?? '')),
        'address' => trim((string)($s['winner_address'] ?? '')),
    ];
    if (buyer_key($buyer) === '') return $members;
    $idx = find_member_index($members, $buyer);
    $m = $idx >= 0 ? $members[$idx] : ['id' => uid('m_'), 'name' => '', 'facebook' => '', 'phone' => '', 'address' => '', 'blacklist_status' => '正常', 'risk_level' => '一般', 'blacklist_reason' => '', 'note' => '', 'created_at' => date('c')];
    foreach (['name', 'facebook', 'phone', 'address'] as $f) if ($buyer[$f] !== '') $m[$f] = $buyer[$f];
    if (isset($s['tax_included'])) $m['tax_included'] = $s['tax_included'];
    $m['updated_at'] = date('c');
    if ($idx >= 0) $members[$idx] = $m; else $members[] = $m;
    return rebuild_member_stats($members, $GLOBALS['schedules'], $products);
}
function rebuild_member_stats($members, $schedules, $products) {
    foreach ($members as &$m) {
        $m['last_win_date'] = '';
        $m['total_winning_amount'] = 0;
        $m['total_win_count'] = 0;
        $m['open_order_count'] = 0;
        $m['unpaid_amount'] = 0;
    }
    unset($m);
    foreach ($schedules as $s) {
        $buyer = ['name' => $s['winner'] ?? '', 'facebook' => $s['winner_facebook'] ?? '', 'phone' => $s['winner_phone'] ?? ''];
        $idx = find_member_index($members, $buyer);
        if ($idx < 0) continue;
        $t = totals($s, product_by_id($products, $s['product_id'] ?? ''));
        $members[$idx]['last_win_date'] = max((string)($members[$idx]['last_win_date'] ?? ''), substr((string)($s['close_at'] ?? ''), 0, 10));
        $members[$idx]['total_winning_amount'] = (float)($members[$idx]['total_winning_amount'] ?? 0) + $t['receivable'];
        $members[$idx]['total_win_count'] = (int)($members[$idx]['total_win_count'] ?? 0) + 1;
        if (!in_array(($s['order_status'] ?? '待記單'), ['已出貨', '完成', '取消'], true)) $members[$idx]['open_order_count']++;
        $members[$idx]['unpaid_amount'] += $t['unpaid'];
    }
    return $members;
}
function member_matches($m, $q) {
    if ($q === '') return true;
    $hay = implode(' ', [$m['name'] ?? '', $m['facebook'] ?? '', $m['phone'] ?? '', $m['address'] ?? '', $m['blacklist_status'] ?? '', $m['risk_level'] ?? '', $m['blacklist_reason'] ?? '', $m['note'] ?? '']);
    return mb_stripos($hay, $q, 0, 'UTF-8') !== false;
}

function cloud_auction_reserved($p) {
    return max(0, (int)($p['cloud_auction_reserved'] ?? 0));
}
function stock_reserved_total($p) {
    return max(0, (int)($p['stock_reserved'] ?? 0)) + cloud_auction_reserved($p);
}
function stock_available($p) {
    return max(0, (int)($p['stock_total'] ?? 0) - stock_reserved_total($p) - (int)($p['stock_sold'] ?? 0));
}
function next_delivery_no($deliveryNotes, $kind = 'sell') {
    return ops_next_doc_no($kind, ops_collect_doc_nos($deliveryNotes));
}
function delivery_no_is_placeholder($no) {
    $no = trim((string)$no);
    if ($no === '') return true;
    return (bool)preg_match('/儲存後自動|自動流水|自動產生/', $no);
}
function delivery_no_needs_repair($no) {
    $no = trim((string)$no);
    if ($no === '') return false;
    if (delivery_no_is_placeholder($no)) return true;
    return strpos($no, 'PHT-SHIP-') === 0 && !preg_match('/^PHT-SHIP-\d{8}-\d+$/', $no);
}
function assign_sales_delivery_no($posted, $deliveryNotes, $existingNo = '', $kind = 'sell') {
    $posted = trim((string)$posted);
    $existingNo = trim((string)$existingNo);
    if (!delivery_no_is_placeholder($existingNo) && ops_doc_no_is_valid($existingNo)) return $existingNo;
    if (!delivery_no_is_placeholder($posted) && (ops_doc_no_is_valid($posted, $kind) || ops_doc_no_is_valid($posted))) return $posted;
    return next_delivery_no($deliveryNotes, $kind);
}
function find_delivery_note($deliveryNotes, $id) {
    $id = trim((string)$id);
    if ($id === '') return null;
    foreach ($deliveryNotes as $note) {
        if (!is_array($note)) continue;
        if ((string)($note['id'] ?? '') === $id || (string)($note['delivery_no'] ?? '') === $id) return $note;
    }
    return null;
}
function find_delivery_note_index($deliveryNotes, $id) {
    $id = trim((string)$id);
    if ($id === '') return -1;
    foreach ($deliveryNotes as $i => $note) {
        if (!is_array($note)) continue;
        if ((string)($note['id'] ?? '') === $id || (string)($note['delivery_no'] ?? '') === $id) return (int)$i;
    }
    return -1;
}
function delivery_note_qty_bonus($items) {
    $bonus = [];
    if (!is_array($items)) return $bonus;
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $key = strtoupper(trim((string)($item['product_id'] ?? $item['product_barcode'] ?? '')));
        $qty = max(0, (int)($item['quantity'] ?? 0));
        if ($key === '' || $qty <= 0) continue;
        $bonus[$key] = ($bonus[$key] ?? 0) + $qty;
    }
    return $bonus;
}
function restore_delivery_note_stock(&$products, $items) {
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $key = trim((string)($item['product_id'] ?? $item['product_barcode'] ?? ''));
        $qty = max(0, (int)($item['quantity'] ?? 0));
        if ($key === '' || $qty <= 0) continue;
        $idx = find_product_key($products, $key);
        if ($idx < 0) continue;
        $products[$idx]['stock_sold'] = max(0, (int)($products[$idx]['stock_sold'] ?? 0) - $qty);
        $products[$idx]['updated_at'] = date('c');
    }
}
function strip_stock_movements_for_delivery($stockMovements, $deliveryNo) {
    $deliveryNo = trim((string)$deliveryNo);
    if ($deliveryNo === '' || !is_array($stockMovements)) return is_array($stockMovements) ? array_values($stockMovements) : [];
    return array_values(array_filter($stockMovements, function($row) use ($deliveryNo) {
        if (!is_array($row)) return false;
        return (string)($row['document_no'] ?? '') !== $deliveryNo && (string)($row['source_doc_no'] ?? '') !== $deliveryNo;
    }));
}
function delivery_note_should_adjust_stock($note, $stockMovements) {
    $source = (string)($note['source'] ?? '');
    if ($source === 'sales_out' || $source === '') return true;
    $no = trim((string)($note['delivery_no'] ?? ''));
    if ($no === '') return false;
    foreach ($stockMovements as $row) {
        if (!is_array($row)) continue;
        if ((string)($row['document_no'] ?? '') === $no || (string)($row['source_doc_no'] ?? '') === $no) return true;
    }
    return false;
}
function next_repair_no($repairDocuments, $dateValue = '') {
    $timestamp = $dateValue !== '' ? strtotime($dateValue) : time();
    if ($timestamp === false) $timestamp = time();
    $prefix = 'PHT-REP-' . date('Ymd', $timestamp) . '-';
    $used = [];
    foreach ($repairDocuments as $r) {
        $no = (string)($r['repair_no'] ?? '');
        if (strpos($no, $prefix) === 0) $used[$no] = true;
    }
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $candidate = $prefix . (string)random_int(1000, 9999);
        if (!isset($used[$candidate])) return $candidate;
    }
    return $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}
function unique_values_from_categories($categories, $field) {
    $values = [];
    foreach ($categories as $c) {
        $v = trim((string)($c[$field] ?? ''));
        if ($v !== '') $values[] = $v;
    }
    $values = array_values(array_unique($values));
    sort($values, SORT_NATURAL);
    return $values;
}
require_once __DIR__ . DIRECTORY_SEPARATOR . 'product-category-tree.php';
function warehouse_items($items, $type) {
    return array_values(array_filter($items, function($i) use ($type) { return ($i['type'] ?? '') === $type; }));
}
function location_values($products, $items, $field, $type) {
    $values = [];
    foreach ($items as $i) if (($i['type'] ?? '') === $type && trim((string)($i['name'] ?? '')) !== '') $values[] = trim((string)$i['name']);
    foreach ($products as $p) {
        $value = trim((string)($p[$field] ?? ''));
        if ($type === 'shelf') $value = normalize_shelf_code($value);
        if ($type === 'layer') $value = normalize_warehouse_layer($value, (string)($p['shelf_code'] ?? ''));
        if ($value !== '') $values[] = $value;
    }
    $values = array_values(array_unique($values));
    sort($values, SORT_NATURAL);
    return $values;
}
function normalize_shelf_code($shelf) {
    $shelf = trim((string)$shelf);
    if ($shelf === '') return '';
    $normalized = preg_replace('/[\(（]\s*(上層|下層)\s*[\)）]/u', '', $shelf);
    return trim((string)$normalized);
}
function normalize_warehouse_layer($location, $shelf = '') {
    $location = trim((string)$location);
    $shelf = trim((string)$shelf);
    foreach ([$location, $shelf] as $value) {
        if (preg_match('/上層/u', $value)) return '上層';
        if (preg_match('/下層/u', $value)) return '下層';
    }
    return in_array($location, ['上層', '下層'], true) ? $location : '';
}
function stock_position_label($shelf, $location) {
    $originalShelf = trim((string)$shelf);
    $shelf = normalize_shelf_code($originalShelf);
    $location = $shelf === '' ? '' : normalize_warehouse_layer($location, $originalShelf);
    if ($shelf === '') return '還沒放上去';
    if ($shelf !== '' && $location !== '') {
        if ($location === $shelf || strpos($location, $shelf . ':') === 0 || strpos($location, $shelf . '：') === 0) {
            return $location;
        }
        return $shelf . ' / ' . $location;
    }
    return $shelf;
}
function ops_document_type_label($type) {
    $type = trim((string)$type);
    $map = [
        '进货入库单' => '進貨入庫單',
        '进货退货单' => '進貨退貨單',
        '销售出库单' => '銷售出庫單',
        '销售退货单' => '銷售退貨單',
        '收款单' => '收款單',
        '付款单' => '付款單',
        '费用单' => '費用單',
        '调拨单' => '調撥單',
        '盘点单' => '盤點單',
    ];
    return $map[$type] ?? $type;
}
function ops_document_flow_type($rowOrType) {
    $type = is_array($rowOrType)
        ? trim((string)($rowOrType['source_doc_type'] ?? ($rowOrType['type'] ?? ($rowOrType['movement_type'] ?? ''))))
        : trim((string)$rowOrType);
    $label = ops_document_type_label($type);
    $labelLower = mb_strtolower($label . ' ' . $type, 'UTF-8');
    if (mb_stripos($labelLower, '進貨退', 0, 'UTF-8') !== false || mb_stripos($labelLower, '进货退', 0, 'UTF-8') !== false || mb_stripos($labelLower, 'purchase_return', 0, 'UTF-8') !== false) return 'purchase_return';
    if (mb_stripos($labelLower, '銷售退', 0, 'UTF-8') !== false || mb_stripos($labelLower, '銷貨退', 0, 'UTF-8') !== false || mb_stripos($labelLower, '销售退', 0, 'UTF-8') !== false || mb_stripos($labelLower, 'sales_return', 0, 'UTF-8') !== false) return 'sales_return';
    if (mb_stripos($labelLower, '銷售出', 0, 'UTF-8') !== false || mb_stripos($labelLower, '銷貨出', 0, 'UTF-8') !== false || mb_stripos($labelLower, '销售出', 0, 'UTF-8') !== false || mb_stripos($labelLower, 'sale', 0, 'UTF-8') !== false) return 'sales_out';
    if (mb_stripos($labelLower, '進貨', 0, 'UTF-8') !== false || mb_stripos($labelLower, '进货', 0, 'UTF-8') !== false || mb_stripos($labelLower, 'purchase', 0, 'UTF-8') !== false) return 'purchase_in';
    return 'other';
}
function ops_document_date_value($row) {
    foreach (['document_date', 'date', 'delivery_date', 'handled_date', 'created_at', 'updated_at'] as $field) {
        $value = trim((string)($row[$field] ?? ''));
        if ($value !== '') return substr($value, 0, 10);
    }
    return '';
}
function ops_money_amount($row, $fallback = 0) {
    foreach (['total_amount', 'amount', 'line_subtotal', 'refund_amount', 'paid_amount', 'received_amount'] as $field) {
        if (isset($row[$field]) && is_numeric($row[$field])) return abs((float)$row[$field]);
    }
    return abs((float)$fallback);
}
function ops_is_payment_import_row($row) {
    if (!is_array($row)) return false;
    $id = (string)($row['id'] ?? '');
    if (strncmp($id, 'paydoc_', 7) === 0) return true;
    $source = (string)($row['source'] ?? '');
    if ($source === '') return false;
    return mb_stripos($source, '收付款账户明细', 0, 'UTF-8') !== false
        || mb_stripos($source, '收付款帳戶明細', 0, 'UTF-8') !== false;
}
function ops_movement_qty($mv) {
    $qty = abs((float)($mv['qty'] ?? 0));
    if ($qty <= 0 && !empty($mv['lines']) && is_array($mv['lines'])) {
        foreach ($mv['lines'] as $line) {
            $qty += abs((float)($line['qty'] ?? $line['quantity'] ?? 0));
        }
    }
    return $qty;
}
function ops_erp_finance_empty_row($label, $start, $end) {
    return [
        'label' => $label,
        'start' => $start,
        'end' => $end,
        'stock_in_qty' => 0,
        'stock_in_cost' => 0,
        'purchase_return_qty' => 0,
        'purchase_return_amount' => 0,
        'sales_qty' => 0,
        'sales_revenue' => 0,
        'sales_return_qty' => 0,
        'sales_return_amount' => 0,
        'sales_cost' => 0,
        'sales_return_cost' => 0,
        'gross_profit' => 0,
        'loss_amount' => 0,
        'order_count' => 0,
    ];
}
function warehouse_count($products, $warehouse) {
    $sum = 0;
    foreach ($products as $p) if (($p['warehouse_name'] ?? '') === $warehouse) $sum += stock_available($p);
    return $sum;
}

function warehouse_layer_rank($layer) {
    $order = ['上層'=>10, '第一層'=>10, '1層'=>10, '中層'=>20, '第二層'=>20, '2層'=>20, '下層'=>30, '第三層'=>30, '3層'=>30];
    return $order[$layer] ?? 100;
}
function department_warehouse_map() {
    return [
        '電腦部門' => ['電腦倉', '台灣倉', '中國倉'],
        '服裝部門' => ['台灣倉', '中國倉', '印尼倉'],
    ];
}

function default_warehouse_name($department = '電腦部門') {
    $map = department_warehouse_map();
    $dept = trim((string)$department);
    if ($dept !== '' && !empty($map[$dept][0])) return $map[$dept][0];
    return '電腦倉';
}

function warehouse_department_for($warehouse, $fallback = '電腦部門') {
    foreach (department_warehouse_map() as $dept => $warehouses) {
        if (in_array($warehouse, $warehouses, true)) return $dept;
    }
    if ($warehouse === '電腦倉') return '電腦部門';
    if ($warehouse === '印尼倉' || $warehouse === '服裝倉') return '服裝部門';
    return $fallback ?: '電腦部門';
}

function warehouse_tree($items) {
    $tree = [];
    foreach (department_warehouse_map() as $dept => $warehouses) {
        foreach ($warehouses as $warehouseName) {
            $tree[$dept][$warehouseName] = ['department' => $dept, 'name' => $warehouseName, 'shelves' => []];
        }
    }
    foreach ($items as $item) {
        $type = $item['type'] ?? '';
        $warehouse = trim((string)($item['warehouse'] ?? ''));
        $name = trim((string)($item['name'] ?? ''));
        if ($type === 'warehouse') {
            $warehouse = $name;
        }
        if ($warehouse === '服裝倉') $warehouse = '台灣倉';
        if ($warehouse === '') $warehouse = '未指定主倉位';
        $dept = trim((string)($item['department'] ?? ''));
        if ($dept === '') $dept = warehouse_department_for($warehouse);
        if (!isset($tree[$dept])) $tree[$dept] = [];
        if (!isset($tree[$dept][$warehouse])) $tree[$dept][$warehouse] = ['department' => $dept, 'name' => $warehouse, 'shelves' => []];
        if ($type === 'shelf' || $type === 'combo') {
            $shelf = trim((string)($item['shelf'] ?? ''));
            if ($shelf === '') $shelf = $type === 'shelf' ? $name : '';
            $shelfKey = $shelf !== '' ? $shelf : '未指定貨架';
            if (!isset($tree[$dept][$warehouse]['shelves'][$shelfKey])) $tree[$dept][$warehouse]['shelves'][$shelfKey] = ['name' => $shelfKey, 'layers' => []];
            $layer = trim((string)($item['layer'] ?? ''));
            if ($layer !== '') $tree[$dept][$warehouse]['shelves'][$shelfKey]['layers'][$layer] = $layer;
        }
    }
    uksort($tree, 'strnatcasecmp');
    $flat = [];
    foreach ($tree as $dept => $warehouses) {
        uksort($warehouses, 'strnatcasecmp');
        foreach ($warehouses as $warehouseName => $warehouse) {
            uksort($warehouse['shelves'], 'strnatcasecmp');
            foreach ($warehouse['shelves'] as &$shelf) {
                $layers = array_values($shelf['layers']);
                usort($layers, function($a, $b) {
                    $rank = warehouse_layer_rank($a) <=> warehouse_layer_rank($b);
                    return $rank ?: strnatcasecmp($a, $b);
                });
                $shelf['layers'] = $layers;
            }
            unset($shelf);
            $warehouse['department'] = $dept;
            $flat[] = $warehouse;
        }
    }
    return $flat;
}

function ensure_warehouse_shelf(&$warehouses, $department, $warehouse, $shelf) {
    $warehouse = trim((string)$warehouse);
    $shelf = normalize_shelf_code($shelf);
    $department = trim((string)$department);
    if ($warehouse === '' || $shelf === '') return false;
    if ($department === '') $department = warehouse_department_for($warehouse);
    foreach ($warehouses as $row) {
        if (!is_array($row)) continue;
        $type = (string)($row['type'] ?? '');
        if ($type !== 'shelf' && $type !== 'combo') continue;
        $rowWarehouse = trim((string)($row['warehouse'] ?? ''));
        $rowShelf = normalize_shelf_code($row['shelf'] ?? '');
        if ($rowShelf === '' && $type === 'shelf') $rowShelf = normalize_shelf_code($row['name'] ?? '');
        $rowDepartment = trim((string)($row['department'] ?? ''));
        if ($rowWarehouse === $warehouse && $rowShelf === $shelf && ($rowDepartment === '' || $rowDepartment === $department)) {
            return false;
        }
    }
    $warehouses[] = [
        'id' => uid('wh_'),
        'department' => $department,
        'type' => 'shelf',
        'name' => $shelf,
        'warehouse' => $warehouse,
        'shelf' => $shelf,
        'layer' => '',
        'created_at' => date('c'),
    ];
    return true;
}


function scan_lines_from_text($text) {
    $rows = [];
    foreach (preg_split('/\R+/', (string)$text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = preg_split('/[\s,，\t]+/u', $line);
        $code = trim((string)($parts[0] ?? ''));
        if ($code === '') continue;
        $qty = 1;
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $qty = max(0, (int)$parts[1]);
            $note = trim(implode(' ', array_slice($parts, 2)));
        } else {
            $note = trim(implode(' ', array_slice($parts, 1)));
        }
        $checkedAt = '';
        if (preg_match('/(?:^|\s)@([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}(?::[0-9]{2})?)(?:\s|$)/', $note, $m)) {
            $checkedAt = str_replace('T', ' ', $m[1]);
            $note = trim(str_replace($m[0], ' ', $note));
        }
        $rows[] = ['code' => $code, 'qty' => $qty, 'note' => $note, 'checked_at' => $checkedAt, 'raw' => $line];
    }
    return $rows;
}
function product_scan_label($p, $fallback = '') {
    $title = trim((string)($p['title'] ?? ''));
    $id = trim((string)($p['id'] ?? $fallback));
    $barcode = trim((string)($p['barcode'] ?? ''));
    $spec = trim(implode(' / ', array_filter([$p['color'] ?? '', $p['size'] ?? '', $p['spec'] ?? ''], function($v) { return trim((string)$v) !== ''; })));
    return trim($id . ($barcode !== '' ? ' / ' . $barcode : '') . ($title !== '' ? ' / ' . $title : '') . ($spec !== '' ? ' / ' . $spec : ''));
}
function next_inventory_doc_no($prefix, $rows) {
    $base = $prefix . '-' . date('Ymd') . '-';
    $max = 0;
    foreach ($rows as $row) {
        $doc = (string)($row['doc_no'] ?? '');
        if (strpos($doc, $base) === 0) $max = max($max, (int)substr($doc, strlen($base)));
    }
    return $base . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}
function next_document_workflow_no($rows) {
    $prefix = 'DOC-' . date('Ymd') . '-';
    $max = 0;
    foreach ($rows as $row) {
        $number = (string)($row['workflow_no'] ?? '');
        if (strpos($number, $prefix) === 0) $max = max($max, (int)substr($number, strlen($prefix)));
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}
function next_billing_request_no($rows) {
    $prefix = 'AR-' . date('Ymd') . '-';
    $max = 0;
    foreach ($rows as $row) {
        $number = (string)($row['request_no'] ?? '');
        if (strpos($number, $prefix) === 0) $max = max($max, (int)substr($number, strlen($prefix)));
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}
function next_bad_debt_no($rows) {
    $prefix = 'BD-' . date('Ymd') . '-';
    $max = 0;
    foreach ($rows as $row) {
        $number = (string)($row['case_no'] ?? '');
        if (strpos($number, $prefix) === 0) $max = max($max, (int)substr($number, strlen($prefix)));
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}
function next_fixed_expense_payment_no($rows) {
    $prefix = 'FE-' . date('Ymd') . '-';
    $max = 0;
    foreach ($rows as $row) {
        $number = (string)($row['payment_no'] ?? '');
        if (strpos($number, $prefix) === 0) $max = max($max, (int)substr($number, strlen($prefix)));
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}
function next_fixed_asset_no($rows, $dateValue = '') {
    $dateValue = trim((string)$dateValue);
    $dateTs = $dateValue !== '' ? strtotime($dateValue) : time();
    if ($dateTs === false) $dateTs = time();
    $prefix = 'FA-' . date('Ymd', $dateTs) . '-';
    $max = 0;
    foreach ($rows as $row) {
        $number = (string)($row['asset_no'] ?? '');
        if (strpos($number, $prefix) === 0) $max = max($max, (int)substr($number, strlen($prefix)));
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}
function next_mobile_asset_no($rows, $dateValue = '') {
    $dateTs = trim((string)$dateValue) !== '' ? strtotime($dateValue) : time();
    if ($dateTs === false) $dateTs = time();
    $prefix = 'MA-' . date('Ymd', $dateTs) . '-';
    $max = 0;
    foreach ($rows as $row) {
        $number = (string)($row['mobile_asset_no'] ?? '');
        if (strpos($number, $prefix) === 0) $max = max($max, (int)substr($number, strlen($prefix)));
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}
function next_mobile_asset_movement_no($rows, $dateValue = '') {
    $dateTs = trim((string)$dateValue) !== '' ? strtotime($dateValue) : time();
    if ($dateTs === false) $dateTs = time();
    $prefix = 'AM-' . date('Ymd', $dateTs) . '-';
    $max = 0;
    foreach ($rows as $row) {
        $number = (string)($row['movement_no'] ?? '');
        if (strpos($number, $prefix) === 0) $max = max($max, (int)substr($number, strlen($prefix)));
    }
    return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
}
function fixed_asset_depreciation_snapshot($asset, $asOf = '') {
    $asOf = trim((string)$asOf) ?: date('Y-m-d');
    $cost = max(0, (float)($asset['acquisition_cost'] ?? 0));
    $residual = min($cost, max(0, (float)($asset['residual_value'] ?? 0)));
    $method = trim((string)($asset['depreciation_method'] ?? '直線法'));
    $lifeMonths = max(1, (int)($asset['useful_life_months'] ?? 60));
    $startDate = trim((string)($asset['depreciation_start_date'] ?? $asset['acquisition_date'] ?? ''));
    $endDate = $asOf;
    $disposedDate = trim((string)($asset['disposed_date'] ?? ''));
    if ($disposedDate !== '' && $disposedDate < $endDate) $endDate = $disposedDate;
    if ($method === '不折舊' || $startDate === '' || $cost <= 0 || strtotime($startDate) === false || strtotime($endDate) === false || $endDate < $startDate) {
        return ['monthly' => 0, 'months' => 0, 'accumulated' => 0, 'book_value' => $cost, 'residual_value' => $residual];
    }
    $startMonthTs = strtotime(substr($startDate, 0, 7) . '-01');
    $endMonthTs = strtotime(substr($endDate, 0, 7) . '-01');
    $months = ((int)date('Y', $endMonthTs) - (int)date('Y', $startMonthTs)) * 12
        + ((int)date('n', $endMonthTs) - (int)date('n', $startMonthTs)) + 1;
    $months = max(0, min($lifeMonths, $months));
    $depreciable = max(0, $cost - $residual);
    $monthly = $depreciable / $lifeMonths;
    $accumulated = min($depreciable, $monthly * $months);
    $currentPeriod = substr($asOf, 0, 7);
    $startPeriod = substr($startDate, 0, 7);
    $endPeriod = date('Y-m', strtotime('+' . ($lifeMonths - 1) . ' months', $startMonthTs));
    $currentMonthly = ($currentPeriod >= $startPeriod && $currentPeriod <= $endPeriod && ($disposedDate === '' || $currentPeriod <= substr($disposedDate, 0, 7))) ? $monthly : 0;
    return [
        'monthly' => $currentMonthly,
        'months' => $months,
        'accumulated' => $accumulated,
        'book_value' => max($residual, $cost - $accumulated),
        'residual_value' => $residual,
    ];
}
function fixed_expense_due_date($period, $paymentDay) {
    if (!preg_match('/^\d{4}-\d{2}$/', (string)$period)) return date('Y-m-d');
    $day = max(1, min(31, (int)$paymentDay));
    $monthStart = strtotime($period . '-01');
    if ($monthStart === false) return date('Y-m-d');
    $day = min($day, (int)date('t', $monthStart));
    return $period . '-' . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
}
function fixed_expense_matches_period($template, $period, $respectAuto = true) {
    if (($template['status'] ?? '') !== '啟用') return false;
    if ($respectAuto && empty($template['auto_generate'])) return false;
    if (!preg_match('/^\d{4}-\d{2}$/', (string)$period)) return false;
    $startMonth = substr((string)($template['start_date'] ?? ''), 0, 7);
    if (!preg_match('/^\d{4}-\d{2}$/', $startMonth)) $startMonth = $period;
    $endMonth = substr((string)($template['end_date'] ?? ''), 0, 7);
    if ($period < $startMonth || ($endMonth !== '' && $period > $endMonth)) return false;
    $startTs = strtotime($startMonth . '-01');
    $periodTs = strtotime($period . '-01');
    if ($startTs === false || $periodTs === false) return false;
    $monthDiff = ((int)date('Y', $periodTs) - (int)date('Y', $startTs)) * 12
        + ((int)date('n', $periodTs) - (int)date('n', $startTs));
    if ($monthDiff < 0) return false;
    $frequencyMonths = ['每月' => 1, '每季' => 3, '每半年' => 6, '每年' => 12];
    $frequency = $frequencyMonths[$template['frequency'] ?? '每月'] ?? 1;
    return $monthDiff % $frequency === 0;
}
function generate_fixed_expense_payments($templates, $payments, $period, $respectAuto = true) {
    $created = 0;
    foreach ($templates as $template) {
        if (!fixed_expense_matches_period($template, $period, $respectAuto)) continue;
        $templateId = (string)($template['id'] ?? '');
        if ($templateId === '') continue;
        $exists = false;
        foreach ($payments as $payment) {
            if (($payment['fixed_expense_id'] ?? '') === $templateId && ($payment['period'] ?? '') === $period) {
                $exists = true;
                break;
            }
        }
        if ($exists) continue;
        $dueDate = fixed_expense_due_date($period, $template['payment_day'] ?? 1);
        $amount = ($template['amount_type'] ?? '固定金額') === '固定金額' ? max(0, (float)($template['default_amount'] ?? 0)) : 0;
        $payments[] = [
            'id' => uid('fep_'),
            'payment_no' => next_fixed_expense_payment_no($payments),
            'fixed_expense_id' => $templateId,
            'expense_name' => $template['name'] ?? '',
            'expense_category_id' => $template['expense_category_id'] ?? '',
            'account_code' => $template['account_code'] ?? '',
            'category_name' => $template['category_name'] ?? '',
            'supplier_id' => $template['supplier_id'] ?? '',
            'supplier_name' => $template['supplier_name'] ?? '',
            'period' => $period,
            'due_date' => $dueDate,
            'amount' => $amount,
            'status' => $dueDate < date('Y-m-d') ? '逾期' : '待付款',
            'paid_date' => '',
            'payment_method' => $template['payment_method'] ?? '',
            'account_name' => $template['account_name'] ?? '',
            'invoice_no' => '',
            'handler' => '',
            'note' => '',
            'operator' => current_operator(),
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        $created++;
    }
    return [$payments, $created];
}
function refresh_fixed_expense_overdue($payments) {
    $changed = false;
    $today = date('Y-m-d');
    foreach ($payments as &$payment) {
        $status = (string)($payment['status'] ?? '待付款');
        $dueDate = (string)($payment['due_date'] ?? '');
        if (in_array($status, ['待付款', '待確認'], true) && $dueDate !== '' && $dueDate < $today) {
            $payment['status'] = '逾期';
            $payment['updated_at'] = date('c');
            $changed = true;
        }
    }
    unset($payment);
    return [$payments, $changed];
}
function confirmed_receipt_paid_for_schedule($receipts, $scheduleId, $orderNo = '') {
    $paid = 0;
    foreach ($receipts as $receipt) {
        if (!in_array(($receipt['status'] ?? ''), ['已確認','部分收款'], true)) continue;
        $allocations = $receipt['allocations'] ?? [];
        if (is_array($allocations) && $allocations) {
            foreach ($allocations as $allocation) {
                if (($allocation['schedule_id'] ?? '') === $scheduleId || ($orderNo !== '' && ($allocation['document_no'] ?? '') === $orderNo)) $paid += max(0, (float)($allocation['amount'] ?? 0));
            }
            continue;
        }
        $documentNos = $receipt['document_nos'] ?? [];
        if (!is_array($documentNos) || !$documentNos) $documentNos = preg_split('/\s*[,、]\s*/u', trim((string)($receipt['document_no'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        if (in_array($scheduleId, $documentNos, true) || ($orderNo !== '' && in_array($orderNo, $documentNos, true))) $paid += max(0, (float)($receipt['amount'] ?? 0));
    }
    return $paid;
}
function scan_to_product_line($products, $scan) {
    $idx = find_product_key($products, $scan['code'] ?? '');
    $p = $idx >= 0 ? $products[$idx] : [];
    $systemQty = $idx >= 0 ? max(0, (int)($p['stock_total'] ?? $p['stock'] ?? 0)) : 0;
    return [
        'scan_code' => $scan['code'] ?? '',
        'product_id' => $p['id'] ?? '',
        'barcode' => $p['barcode'] ?? '',
        'title' => $p['title'] ?? '',
        'color' => $p['color'] ?? '',
        'size' => $p['size'] ?? '',
        'spec' => $p['spec'] ?? '',
        'warehouse_name' => $p['warehouse_name'] ?? '',
        'shelf_code' => $p['shelf_code'] ?? '',
        'warehouse_location' => $p['warehouse_location'] ?? '',
        'qty' => (int)($scan['qty'] ?? 1),
        'system_qty' => (int)$systemQty,
        'diff_qty' => (int)($scan['qty'] ?? 1) - (int)$systemQty,
        'unit_cost' => (float)($p['cost'] ?? 0),
        'note' => $scan['note'] ?? '',
        'checked_at' => $scan['checked_at'] ?? date('Y-m-d H:i:s'),
        'found' => $idx >= 0 ? 1 : 0,
    ];
}

function purchase_cost_default_settings() {
    return [
        'id' => 'purchase_cost_settings',
        'rmb_fixed_rate' => 5,
        'china_warehouse_fee' => 30,
        'taiwan_warehouse_fee' => 20,
        'default_allocation_method' => 'quantity',
    ];
}

function purchase_allocate_amount($amount, $lines, $method = 'quantity') {
    $amount = round(max(0, (float)$amount), 4);
    $allocations = array_fill(0, count($lines), 0.0);
    if ($amount <= 0 || !$lines) return $allocations;

    $weights = [];
    foreach ($lines as $line) {
        $weights[] = $method === 'amount'
            ? max(0, (float)($line['base_cost_twd'] ?? 0))
            : max(0, (float)($line['qty'] ?? 0));
    }
    $weightTotal = array_sum($weights);
    if ($weightTotal <= 0) return $allocations;

    $allocated = 0.0;
    $last = count($lines) - 1;
    foreach ($lines as $index => $line) {
        if ($index === $last) {
            $allocations[$index] = round($amount - $allocated, 4);
        } else {
            $share = round($amount * ($weights[$index] / $weightTotal), 4);
            $allocations[$index] = $share;
            $allocated += $share;
        }
    }
    return $allocations;
}

function purchase_warehouse_fee_unit($warehouse, $settings) {
    $warehouse = trim((string)$warehouse);
    if ($warehouse !== '' && (mb_strpos($warehouse, '中國') !== false || mb_strpos($warehouse, '東莞') !== false)) {
        return max(0, (float)($settings['china_warehouse_fee'] ?? 30));
    }
    if ($warehouse !== '' && (mb_strpos($warehouse, '台灣') !== false || mb_strpos($warehouse, '寶輝') !== false || mb_strpos($warehouse, '電腦') !== false)) {
        return max(0, (float)($settings['taiwan_warehouse_fee'] ?? 20));
    }
    return 0.0;
}

function purchase_is_china_warehouse($warehouse) {
    $warehouse = trim((string)$warehouse);
    return $warehouse !== '' && (mb_strpos($warehouse, '中國') !== false || mb_strpos($warehouse, '東莞') !== false);
}

function purchase_allocate_by_weight($amount, $lines) {
    $amount = round(max(0, (float)$amount), 4);
    $allocations = array_fill(0, count($lines), 0.0);
    if ($amount <= 0 || !$lines) return $allocations;

    $weights = [];
    foreach ($lines as $line) {
        $skip = purchase_is_china_warehouse($line['warehouse_name'] ?? '');
        $weights[] = $skip ? 0.0 : max(0, (float)($line['weight_kg'] ?? 0));
    }
    $weightTotal = array_sum($weights);
    if ($weightTotal <= 0) return $allocations;

    $allocated = 0.0;
    $lastWeighted = -1;
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if ($weights[$i] > 0) {
            $lastWeighted = $i;
            break;
        }
    }
    foreach ($lines as $index => $line) {
        if ($weights[$index] <= 0) continue;
        if ($index === $lastWeighted) {
            $allocations[$index] = round($amount - $allocated, 4);
        } else {
            $share = round($amount * ($weights[$index] / $weightTotal), 4);
            $allocations[$index] = $share;
            $allocated += $share;
        }
    }
    return $allocations;
}

function lingzanzan_stock_in_formula($source, $directCost = false) {
    if ($directCost) return '舊系統現貨直接成本，不加運費稅費';
    if ($source === '拼多多') {
        return '人民幣×匯率 + 台灣快遞÷關稅登記件數 + 倉別人事（關稅只作核對；商品價已含中國運費；東莞倉免集運分攤只加人事）';
    }
    if ($source === '豪鴻') {
        return '人民幣×匯率 + 豪鴻運費依計費重量分攤 + 倉別人事（免稅；東莞倉免集運分攤只加人事）';
    }
    return '基礎台幣成本 - 折扣 + 稅額 + 關稅 + 中國運費 + 台灣快遞 + 整批運費 + 其他費用 + 倉別加價';
}

function latest_cost_barcode($product) {
    $base = product_serial_base($product);
    return build_product_cost_barcode(
        $base,
        $product['cost'] ?? 0,
        $product['color_code'] ?? '',
        $product['size_code'] ?? ''
    );
}

$products = read_data('products');
$schedules = read_data('schedules');
$members = read_data('members');
$returns = read_data('returns');
$repairDocuments = read_data('repair_documents');
$suppliers = read_data('suppliers');
$stockMovements = read_data('stock_movements');
$productCategories = read_data('product_categories');
$deliveryNotes = read_data('delivery_notes');
$paymentRecords = read_data('payment_records');
$financeExpenseCategories = read_data('finance_expense_categories');
$financeOtherIncome = read_data('finance_other_income');
$financeOtherIncomeTotal = array_sum(array_map(function($r) { return (float)($r['ending_balance'] ?? 0); }, $financeOtherIncome));
$financeOtherIncomeEnabled = count(array_filter($financeOtherIncome, function($r) { return ($r['status'] ?? '') === '啟用'; }));
$financeOtherIncomeYear = date('Y');

$collectionReceipts = read_data('collection_receipts');
$billingRequests = read_data('billing_requests');
$badDebts = read_data('bad_debts');
$fixedExpenses = read_data('fixed_expenses');
$fixedExpensePayments = read_data('fixed_expense_payments');
$fixedAssets = read_data('fixed_assets');
$mobileAssets = read_data('mobile_assets');
$mobileAssetMovements = read_data('mobile_asset_movements');
$warehouses = read_data('warehouses');
$logistics = read_data('logistics');
if (!$logistics) { $logistics = [['id'=>'logi_711','name'=>'7-11'], ['id'=>'logi_family','name'=>'全家'], ['id'=>'logi_blackcat','name'=>'黑貓宅急便'], ['id'=>'logi_post','name'=>'郵局'], ['id'=>'logi_hct','name'=>'新竹物流']]; }
$companyProfileRows = read_data('company_profile');
$companyProfile = $companyProfileRows[0] ?? [
    'company_name' => '寶輝科技有限公司',
    'phone' => '039-773280',
    'fax' => '039-773669',
    'address' => '',
    'contact_name' => '',
    'email' => '',
    'line_id' => '',
    'wechat_id' => '',
];
$colorModules = read_data('color_modules');
$mainEmployees = baohui_main_employees();
$opsStaffPermissions = read_data('ops_staff_permissions');
$documentWorkflows = read_data('document_workflows');
$inventoryCounts = read_data('inventory_counts');
$inventoryTransfers = read_data('inventory_transfers');
$inventoryAdjustments = read_data('inventory_adjustments');
$purchaseBatches = read_data('purchase_batches');
$purchaseCostAudits = read_data('purchase_cost_audits');
$purchaseCostRules = read_data('purchase_cost_rules');
$marketplaceCategoryMappings = read_data('marketplace_category_mappings');
$marketplaceListingDrafts = read_data('marketplace_listing_drafts');
$marketplaceMallCandidates = read_data('marketplace_mall_candidates');
$productSpecs = normalize_spec_options(read_data('product_specs'));
foreach (['members', 'returns', 'repair_documents', 'suppliers', 'stock_movements', 'product_categories', 'delivery_notes', 'payment_records', 'finance_expense_categories', 'finance_other_income', 'collection_receipts', 'billing_requests', 'bad_debts', 'fixed_expenses', 'fixed_expense_payments', 'fixed_assets', 'mobile_assets', 'mobile_asset_movements', 'warehouses', 'company_profile', 'color_modules', 'ops_staff_permissions', 'document_workflows', 'inventory_counts', 'inventory_transfers', 'inventory_adjustments', 'purchase_batches', 'purchase_cost_audits', 'purchase_cost_rules', 'marketplace_category_mappings', 'marketplace_listing_drafts', 'marketplace_mall_candidates', 'product_specs', 'post_reply_sets'] as $name) if (!file_exists(data_path($name))) write_data($name, []);
if (marketplace_seed_category_mappings($marketplaceCategoryMappings, $productCategories, function_exists('current_operator') ? current_operator() : 'system')) {
    write_data('marketplace_category_mappings', $marketplaceCategoryMappings);
}
$purchaseCostSettings = array_merge(purchase_cost_default_settings(), is_array($purchaseCostRules[0] ?? null) ? $purchaseCostRules[0] : []);
if (!$purchaseCostRules) {
    $purchaseCostRules = [$purchaseCostSettings];
    write_data('purchase_cost_rules', $purchaseCostRules);
}
$specLibraryChanged = false;
foreach ($products as $productRow) {
    if (remember_product_spec($productSpecs, $productRow['spec'] ?? '')) $specLibraryChanged = true;
}
if ($specLibraryChanged) write_data('product_specs', $productSpecs);
$rawPostReplySets = read_data('post_reply_sets');
if (!$rawPostReplySets) {
    $postReplySets = normalize_post_reply_sets(default_post_reply_sets());
    write_data('post_reply_sets', $postReplySets);
} else {
    $postReplySets = normalize_post_reply_sets($rawPostReplySets);
}
$purchaseSourceOptions = purchase_source_options($suppliers, $products);

$deliveryNoRepaired = false;
foreach ($deliveryNotes as &$deliveryNoteRow) {
    if (!is_array($deliveryNoteRow)) continue;
    $oldDeliveryNo = trim((string)($deliveryNoteRow['delivery_no'] ?? ''));
    if (!delivery_no_needs_repair($oldDeliveryNo)) continue;
    $repairKind = (($deliveryNoteRow['source'] ?? '') === 'quotation') ? 'val' : 'sell';
    $newDeliveryNo = next_delivery_no($deliveryNotes, $repairKind);
    $deliveryNoteRow['delivery_no'] = $newDeliveryNo;
    $deliveryNoteRow['updated_at'] = date('c');
    foreach ($stockMovements as &$movementRow) {
        if (!is_array($movementRow)) continue;
        if ((string)($movementRow['document_no'] ?? '') === $oldDeliveryNo) $movementRow['document_no'] = $newDeliveryNo;
        if ((string)($movementRow['source_doc_no'] ?? '') === $oldDeliveryNo) $movementRow['source_doc_no'] = $newDeliveryNo;
    }
    unset($movementRow);
    $deliveryNoRepaired = true;
}
unset($deliveryNoteRow);
if ($deliveryNoRepaired) {
    write_data('delivery_notes', $deliveryNotes);
    write_data('stock_movements', $stockMovements);
}

if (isset($_GET['print_delivery'])) {
    $printNote = find_delivery_note($deliveryNotes, trim((string)$_GET['print_delivery']));
    if (!$printNote) {
        http_response_code(404);
        echo '<!doctype html><meta charset="utf-8"><p>找不到出貨單。</p>';
        exit;
    }
    $printBuyer = is_array($printNote['buyer'] ?? null) ? $printNote['buyer'] : [];
    $printItems = is_array($printNote['items'] ?? null) ? $printNote['items'] : [];
    $printCompany = trim((string)($companyProfile['company_name'] ?? '寶輝科技有限公司')) ?: '寶輝科技有限公司';
    $printCompanyPhone = trim((string)($companyProfile['phone'] ?? '039-773280')) ?: '039-773280';
    $printCompanyFax = trim((string)($companyProfile['fax'] ?? '039-773669')) ?: '039-773669';
    $printCompanyContact = trim((string)($companyProfile['contact_name'] ?? '郭先生、李先生、曾小姐')) ?: '郭先生、李先生、曾小姐';
    $printCompanyAddress = trim((string)($companyProfile['address'] ?? ''));
    $autoPrint = isset($_GET['autoprint']);
    ?><!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>出貨單 <?=h($printNote['delivery_no'] ?? '')?></title><style>
    body{margin:0;background:#f3f6fb;color:#122033;font-family:"Noto Sans TC","Microsoft JhengHei",Arial,sans-serif}.sheet{max-width:980px;margin:28px auto;background:#fff;border:1px solid #d8e0ea;border-radius:12px;padding:28px}.top{display:flex;justify-content:space-between;gap:16px;border-bottom:3px solid #0f766e;padding-bottom:16px;margin-bottom:18px}.brand h1{margin:0;font-size:28px}.meta{text-align:right;color:#526176}.info{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:16px 0}.box{border:1px solid #d8e0ea;background:#f8fafc;border-radius:8px;padding:12px;line-height:1.7}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{border:1px solid #d8e0ea;padding:8px;text-align:left;vertical-align:top}th{background:#e7f8f3}.right{text-align:right}.actions{max-width:980px;margin:18px auto;text-align:right}.btn{background:#0f766e;color:#fff;border:0;border-radius:8px;padding:10px 16px;font-size:16px;cursor:pointer}.sign{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:28px;border-top:1px solid #d8e0ea;padding-top:18px}.sign-line{height:58px;border-bottom:1px solid #475569}.company{margin-top:18px;padding-top:12px;border-top:1px solid #d8e0ea;color:#334155}@media print{@page{size:A4 portrait;margin:8mm}body{background:#fff}.actions{display:none}.sheet{box-shadow:none;border:0;margin:0;max-width:none;border-radius:0;padding:0}}
    </style><?php if ($autoPrint): ?><script>window.addEventListener('load',function(){setTimeout(function(){window.print();},400);});</script><?php endif; ?></head>
    <body>
    <div class="actions"><button class="btn" type="button" onclick="window.print()">列印出貨單</button></div>
    <main class="sheet">
      <section class="top">
        <div class="brand"><h1><?=h($printCompany)?> 出貨單</h1><p>銷售出庫／客戶簽收聯</p></div>
        <div class="meta">
          <p>出貨單號：<b><?=h($printNote['delivery_no'] ?? '')?></b></p>
          <p>單據日期：<?=h($printNote['date'] ?? '')?></p>
          <p>出貨狀態：<?=h($printNote['shipping_status'] ?? ($printNote['status'] ?? ''))?></p>
          <p>付款狀態：<?=h($printNote['payment_status'] ?? '')?></p>
        </div>
      </section>
      <section class="info">
        <div class="box"><b>客戶</b><br>名稱：<?=h($printBuyer['name'] ?? '')?><br>電話：<?=h($printBuyer['phone'] ?? '')?><br>地址：<?=h($printBuyer['address'] ?? '')?></div>
        <div class="box"><b>物流 / 發票</b><br>物流公司：<?=h($printNote['logistics_company'] ?? '')?><br>物流單號：<?=h($printNote['tracking_no'] ?? '')?><br>發票／憑證：<?=h($printNote['invoice_no'] ?? '')?><br>經手人：<?=h($printNote['handler'] ?? '')?><br>備註：<?=h($printNote['note'] ?? '')?></div>
      </section>
      <table>
        <thead><tr><th>產品</th><th>條碼 / 規格</th><th class="right">數量</th><th class="right">單價</th><th class="right">小計</th><th>倉位</th></tr></thead>
        <tbody>
        <?php foreach ($printItems as $it): if (!is_array($it)) continue; $spec = trim(implode(' / ', array_filter([(string)($it['color'] ?? ''), (string)($it['size'] ?? ''), (string)($it['spec'] ?? '')], function($v){ return trim($v) !== ''; }))); ?>
          <tr>
            <td><?=h($it['product_title'] ?? ($it['product_id'] ?? ''))?></td>
            <td><?=h($it['product_barcode'] ?? '')?><?= $spec !== '' ? '<br>'.h($spec) : '' ?></td>
            <td class="right"><?=h($it['quantity'] ?? 0)?></td>
            <td class="right"><?=money($it['unit_price'] ?? 0)?></td>
            <td class="right"><?=money($it['line_subtotal'] ?? 0)?></td>
            <td><?=h(trim(($it['warehouse_name'] ?? '').' '.($it['shelf_code'] ?? '').' '.($it['warehouse_location'] ?? '')))?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="right">明細合計 <?=money($printNote['line_total'] ?? 0)?>　折扣 <?=money($printNote['discount'] ?? 0)?>　稅額 <?=money($printNote['tax'] ?? 0)?>　運費 <?=money($printNote['shipping_fee'] ?? 0)?>　其他 <?=money($printNote['other_fee'] ?? 0)?><br><b>整單合計 <?=money($printNote['total'] ?? 0)?></b></p>
      <section class="sign"><div><b>客戶簽收</b><div class="sign-line"></div><p>單位 / 姓名：</p><p>日期：</p></div><div><b>寶輝科技經辦</b><div class="sign-line"></div><p>經辦：<?=h($printNote['handler'] ?? '')?></p><p>日期：</p></div></section>
      <div class="company"><?=h($printCompany)?>　電話：<?=h($printCompanyPhone)?>　傳真：<?=h($printCompanyFax)?>　聯絡人：<?=h($printCompanyContact)?><?= $printCompanyAddress !== '' ? '　地址：'.h($printCompanyAddress) : '' ?></div>
    </main>
    </body></html><?php
    exit;
}

if (isset($_GET['print_cost_barcode'])) {
    $printProduct = product_by_key($products, trim((string)$_GET['print_cost_barcode']));
    if (!$printProduct) {
        http_response_code(404);
        echo '<!doctype html><meta charset="utf-8"><p>找不到產品。</p>';
        exit;
    }
    $printBarcode = latest_cost_barcode($printProduct);
    $printTitle = trim((string)($printProduct['title'] ?? $printProduct['product_name'] ?? ''));
    $printSpec = trim(implode(' / ', array_filter([$printProduct['color'] ?? '', $printProduct['size'] ?? '', $printProduct['spec'] ?? ''], function($value) { return trim((string)$value) !== ''; })));
    $printCost = (float)($printProduct['cost'] ?? 0);
    $printCostAt = trim((string)($printProduct['latest_cost_updated_at'] ?? $printProduct['updated_at'] ?? ''));
    $labelSize = ($_GET['label_size'] ?? '40x30') === '30x30' ? '30x30' : '40x30';
    $labelWidth = $labelSize === '30x30' ? 30 : 40;
    $labelHeight = 30;
    $barcodeHeight = $labelSize === '30x30' ? 25 : 29;
    $barcodeFontSize = $labelSize === '30x30' ? 8 : 9;
    ?><!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>產品條碼 <?=h($labelWidth)?>×<?=h($labelHeight)?> mm</title><style>
    *{box-sizing:border-box}body{font-family:Arial,"Microsoft JhengHei",sans-serif;margin:0;padding:20px;background:#eef2f7;color:#111827}.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px}.toolbar a,.toolbar button{border:1px solid #0f766e;border-radius:6px;padding:9px 12px;background:#fff;color:#0f766e;font-weight:800;text-decoration:none;cursor:pointer}.toolbar button{background:#0f766e;color:#fff}.label{width:<?=$labelWidth?>mm;height:<?=$labelHeight?>mm;overflow:hidden;background:#fff;border:1px solid #111;padding:1.2mm;display:flex;flex-direction:column;justify-content:flex-start}.label h1{font-size:<?=($labelSize === '30x30' ? '8px' : '9px')?>;line-height:1.15;margin:0;height:<?=($labelSize === '30x30' ? '9px' : '11px')?>;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.label .spec{font-size:7px;line-height:1.1;margin:1px 0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.label svg{display:block;width:100%;height:<?=($labelSize === '30x30' ? '12mm' : '13mm')?>}.label .meta{display:flex;justify-content:space-between;gap:2px;font-size:7px;font-weight:800;line-height:1.05;margin-top:1px}.label .meta span{overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.label .cost{font-size:7px;font-weight:900;white-space:nowrap;margin-top:1px}.hint{font-size:13px;color:#475569}@media print{body{padding:0;background:#fff}.toolbar,.hint{display:none}.label{border:0;margin:0}@page{size:<?=$labelWidth?>mm <?=$labelHeight?>mm;margin:0}}
    </style></head><body><div class="toolbar"><a href="?print_cost_barcode=<?=urlencode($printProduct['id'] ?? '')?>&label_size=40x30">40×30 mm</a><a href="?print_cost_barcode=<?=urlencode($printProduct['id'] ?? '')?>&label_size=30x30">30×30 mm</a><button type="button" onclick="window.print()">列印此尺寸條碼</button><span class="hint">目前版型：<?=h($labelWidth)?>×<?=h($labelHeight)?> mm｜條碼 <?=h($printBarcode)?></span></div><div class="label"><h1><?=h($printTitle ?: ($printProduct['id'] ?? '產品'))?></h1><div class="spec"><?=h($printSpec ?: '一般規格')?></div><svg id="latestCostBarcode"></svg><div class="meta"><span><?=h($printBarcode)?></span><span>庫存 <?=h((int)($printProduct['stock_total'] ?? 0))?></span></div></div><script src="assets/vendor/JsBarcode.all.min.js"></script><script>if(window.JsBarcode){JsBarcode('#latestCostBarcode',<?=json_encode($printBarcode, json_flags(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))?>,{format:'CODE128',displayValue:true,fontSize:<?=$barcodeFontSize?>,height:<?=$barcodeHeight?>,margin:0,width:1});}</script></body></html><?php
    exit;
}
if (!$productCategories) {
    $productCategories = [
        ['id' => 'pc_computer_mb_asus_lga1700', 'group' => '電腦', 'type' => '主機板', 'brand' => 'ASUS', 'spec' => 'LGA1700', 'sort' => 10, 'note' => '主機板分類範例', 'created_at' => date('c')],
        ['id' => 'pc_computer_mb_msi_am5', 'group' => '電腦', 'type' => '主機板', 'brand' => 'MSI', 'spec' => 'AM5', 'sort' => 20, 'note' => '主機板分類範例', 'created_at' => date('c')],
        ['id' => 'pc_computer_gpu_asus_rtx', 'group' => '電腦', 'type' => '顯示卡', 'brand' => 'ASUS', 'spec' => 'RTX', 'sort' => 30, 'note' => '顯示卡分類範例', 'created_at' => date('c')],
        ['id' => 'pc_computer_gpu_msi_rtx', 'group' => '電腦', 'type' => '顯示卡', 'brand' => 'MSI', 'spec' => 'RTX', 'sort' => 40, 'note' => '顯示卡分類範例', 'created_at' => date('c')],
        ['id' => 'pc_clothes_top_general_size', 'group' => '服裝', 'type' => '上衣', 'brand' => '不指定品牌', 'spec' => '尺寸', 'sort' => 50, 'note' => '服裝分類範例', 'created_at' => date('c')],
        ['id' => 'pc_clothes_pants_general_size', 'group' => '服裝', 'type' => '褲子', 'brand' => '不指定品牌', 'spec' => '尺寸', 'sort' => 60, 'note' => '服裝分類範例', 'created_at' => date('c')],
    ];
    write_data('product_categories', $productCategories);
}
$requiredComputerCategoryTypes = [
    '主機板' => ['group' => '組裝硬體', 'type_code' => 'MB', 'brand' => '不指定品牌', 'spec' => '一般規格', 'note' => '組裝硬體：主機板 → 廠牌 → 規格 → 商品'],
];
$categorySeedChanged = false;
foreach ($requiredComputerCategoryTypes as $typeName => $meta) {
    $exists = false;
    foreach ($productCategories as $row) {
        if (!is_array($row)) continue;
        $group = trim((string)($row['group'] ?? ''));
        $type = trim((string)($row['type'] ?? ''));
        $wantGroup = $meta['group'] ?? '組裝硬體';
        if ($type === $typeName && $group === $wantGroup) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $productCategories[] = [
            'id' => uid('pc_tree_'),
            'group' => $meta['group'] ?? '組裝硬體',
            'type' => $typeName,
            'type_code' => $meta['type_code'],
            'barcode_prefix' => $meta['type_code'],
            'brand' => $meta['brand'],
            'spec' => $meta['spec'],
            'sort' => 80,
            'note' => $meta['note'],
            'created_at' => date('c'),
        ];
        $categorySeedChanged = true;
    }
}
if ($categorySeedChanged) write_data('product_categories', $productCategories);
$requiredWarehouseMap = department_warehouse_map();
$knownWarehouseKeys = [];
foreach ($warehouses as $w) {
    if (($w['type'] ?? '') === 'warehouse' && trim((string)($w['name'] ?? '')) !== '') {
        $knownWarehouseKeys[] = trim((string)($w['department'] ?? warehouse_department_for($w['name'] ?? ''))) . '||' . trim((string)$w['name']);
    }
}
$warehouseSeedChanged = false;
foreach ($requiredWarehouseMap as $deptName => $warehouseNames) {
    foreach ($warehouseNames as $warehouseName) {
        $key = $deptName . '||' . $warehouseName;
        if (!in_array($key, $knownWarehouseKeys, true)) {
            $warehouses[] = [
                'id' => uid('wh_'),
                'department' => $deptName,
                'type' => 'warehouse',
                'name' => $warehouseName,
                'warehouse' => $warehouseName,
                'shelf' => '',
                'layer' => '',
                'created_at' => date('c'),
            ];
            $knownWarehouseKeys[] = $key;
            $warehouseSeedChanged = true;
        }
    }
}
if ($warehouseSeedChanged) write_data('warehouses', $warehouses);


$opsFunctionGroups = [
    '基本資料' => [
        'overview' => '總覽',
        'company-profile' => '本公司資料',
        'suppliers' => '廠商建檔',
        'member-create' => '客戶／會員建檔',
        'members' => '會員搜尋',
        'logistics' => '物流管理',
        'warehouses' => '貨倉管理',
        'staff-permissions' => '員工權限',
    ],
    '產品區管理' => [
        'product-categories' => '產品分類',
        'shopee-workspace' => '多通路上架工作台',
        'color-modules' => '顏色尺碼模組',
        'products' => '產品建檔',
        'stock-search' => '庫存管理',
        'cloud-inventory-sync' => '雲端庫存比對',
    ],
    '單據總覽' => [
        'draft-center' => '草稿中心',
        'document-center' => '單據中心',
        'stock-in' => '進貨單據',
        'customer-shipping' => '銷售單據',
        'quotations' => '估價單',
        'repair-documents' => '維修單據',
        'returns' => '銷貨退回',
    ],
    '自動化排程相關' => [
        'schedule' => '排程上架',
        'facebook-daily' => '臉書當日日報',
        'post-scripts' => '發文問答套組',
        'settlement' => '得標結算',
        'settlement-edit' => '結算編輯',
        'orders' => '記單出貨',
    ],
    '盤點系統' => [
        'inventory-count' => '盤點單據',
        'finance-loss' => '報損單',
        'finance-overage' => '報溢單',
    ],
    '庫存調撥' => [
        'inventory-transfer' => '調撥單據',
    ],
    '財務系統' => [
        'finance-reconcile' => '對帳單',
        'finance-collection' => '收款單',
        'finance-other-income' => '其他收入科目',
        'finance-request' => '請款單',
        'finance-bad-debt' => '呆帳',
        'finance-analytics' => '公司財報',
        'finance-report' => '財務報表',
        'finance-expense-categories' => '費用支出科目',
        'finance-fixed-expense' => '固定開支',
        'finance-fixed-asset' => '固定資產',
        'finance-mobile-asset' => '移動資產',
    ],
];
$opsFunctionMap = [];
foreach ($opsFunctionGroups as $groupName => $items) {
    foreach ($items as $tabId => $label) $opsFunctionMap[$tabId] = $label;
}
$currentOpsAccount = trim((string)(($_SESSION['user']['account'] ?? '') ?: current_operator()));
$currentOpsRole = trim((string)($_SESSION['user']['role'] ?? ''));
$opsRoleAdminNames = array_map('json_decode', ['"\u6700\u9ad8\u7ba1\u7406\u54e1"', '"\u7ba1\u7406\u8005"', '"\u7cfb\u7d71\u7ba1\u7406\u54e1"']);
$opsAccountAdminNames = array_merge(['admin', 'administrator', 'root'], [json_decode('"\u7ba1\u7406\u8005"')]);
$isOpsSuperAdmin = !empty($_SESSION['baohui_is_admin'])
    || in_array($currentOpsRole, $opsRoleAdminNames, true)
    || in_array(strtolower($currentOpsAccount), ['admin', 'administrator', 'root'], true)
    || in_array($currentOpsAccount, $opsAccountAdminNames, true);
$currentOpsStaff = null;
foreach ($opsStaffPermissions as $row) {
    $account = trim((string)($row['account'] ?? ''));
    if ($account !== '' && strcasecmp($account, $currentOpsAccount) === 0) {
        $currentOpsStaff = $row;
        break;
    }
}
$opsAllowedTabs = array_keys($opsFunctionMap);
$opsPermissionRestricted = !$isOpsSuperAdmin;
if ($opsPermissionRestricted && is_array($currentOpsStaff) && !empty($currentOpsStaff['allowed_tabs'])) {
    $opsAllowedTabs = array_values(array_unique(array_merge(['overview'], array_values((array)$currentOpsStaff['allowed_tabs']))));
} elseif ($opsPermissionRestricted) {
    $opsAllowedTabs = ['overview'];
}

if (!$colorModules) {
    $colorModules = [
        ['id' => 'cm_clothes_basic', 'name' => '服裝基本色', 'colors' => ['黑', '白', '灰', '米', '咖啡', '藍', '粉', '紅'], 'sizes' => ['F', 'S', 'M', 'L', 'XL', '2XL'], 'created_at' => date('c')],
        ['id' => 'cm_women', 'name' => '女裝常用', 'colors' => ['黑', '白', '杏', '米', '粉', '藍', '綠'], 'sizes' => ['F', 'S', 'M', 'L', 'XL'], 'created_at' => date('c')],
        ['id' => 'cm_kids', 'name' => '童裝常用', 'colors' => ['粉', '藍', '黃', '綠', '白', '灰'], 'sizes' => ['90', '100', '110', '120', '130', '140', '150'], 'created_at' => date('c')],
    ];
    write_data('color_modules', $colorModules);
}

$barcodeColorCodes = [
    '90' => '紫色 / UNGU', '91' => '黑色 / HITAM', '92' => '白色 / PUTIH', '93' => '紅色 / MERAH', '94' => '黃色 / KUNING', '95' => '綠色 / HIJAU', '96' => '藍色 / BIRU', '97' => '銀色', '98' => '粉紅', '99' => '灰色 / ABU',
    '901' => '無顏色', '902' => '咖色 / Cokelat', '903' => '金色', '904' => '卡其色', '905' => '橘色', '906' => '深藍', '907' => '圖片色', '908' => '淺綠', '909' => '杏色', '911' => '淺藍', '912' => '棕色', '913' => '迷彩', '914' => '米色', '921' => '深灰', '924' => '黑+杏', '925' => '黑+灰', '926' => '黑+酒紅', '928' => '白黑', '929' => '白藍', '930' => '白綠', '931' => '白紅', '932' => '紅白'
];
$barcodeSizeCodes = [
    '00' => 'NO SIZE',
    '1' => 'XS', '2' => 'S', '3' => 'M', '4' => 'L', '5' => 'XL', '6' => '2XL', '7' => '3XL', '8' => '4XL',
    '13' => '1.3', '15' => '1.5', '18' => '1.8',
    '20' => '20吋', '22' => '22吋', '24' => '24吋', '26' => '26吋', '28' => '28吋', '29' => '29吋', '30' => '30吋', '31' => '31',
    '35' => '35', '36' => '36', '37' => '37', '38' => '38', '39' => '39', '40' => '40', '41' => '41', '42' => '42', '43' => '43', '44' => '44', '45' => '45',
    '036' => '36吋',
    '70' => '100CM', '71' => '110CM', '72' => '120CM', '73' => '130CM', '74' => '140CM', '75' => '150CM', '76' => '160CM',
    '200' => '2.0'
];
$sharedColorModuleId = 'cm_clothes_shared';
$hasSharedColorModule = false;
foreach ($colorModules as &$module) {
    if (($module['id'] ?? '') === $sharedColorModuleId) {
        $module['name'] = trim((string)($module['name'] ?? '')) !== '' ? $module['name'] : '服裝部共用碼表（全部）';
        $module['colors'] = array_values($barcodeColorCodes);
        $module['sizes'] = array_values($barcodeSizeCodes);
        $module['system'] = true;
        $hasSharedColorModule = true;
        break;
    }
}
unset($module);
if (!$hasSharedColorModule) {
    array_unshift($colorModules, [
        'id' => $sharedColorModuleId,
        'name' => '服裝部共用碼表（全部）',
        'colors' => array_values($barcodeColorCodes),
        'sizes' => array_values($barcodeSizeCodes),
        'system' => true,
        'created_at' => date('c'),
    ]);
    write_data('color_modules', $colorModules);
}

$notice = '';
$opsInitialTab = '';
$salesOutKeepEditId = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    $marketplaceHubActions = [
        'save_marketplace_category_mapping', 'delete_marketplace_category_mapping',
        'save_shopee_listing_draft', 'save_marketplace_listing_draft',
        'save_marketplace_listing_link', 'publish_marketplace_listing',
        'sync_marketplace_stock', 'export_yahoo_auction_csv',
        'save_marketplace_credentials', 'add_mall_catalog_candidate',
    ];
    if (in_array($action, $marketplaceHubActions, true)) {
        marketplace_current_platform(trim((string)($_POST['platform'] ?? ($_GET['channel'] ?? 'shopee_tw'))));
        $opsInitialTab = 'shopee-workspace';
    }

    if ($action === 'save_marketplace_category_mapping') {
        $mappingId = trim((string)($_POST['mapping_id'] ?? ''));
        $sourceGroup = trim((string)($_POST['source_group'] ?? ''));
        $sourceType = trim((string)($_POST['source_type'] ?? ''));
        $sourceBrand = trim((string)($_POST['source_brand'] ?? ''));
        $sourceSpec = trim((string)($_POST['source_spec'] ?? ''));
        $platformPath = trim((string)($_POST['platform_category_path'] ?? ''));
        $platform = marketplace_current_platform();
        $channel = marketplace_channel($platform);
        if ($sourceGroup === '' || $sourceType === '') {
            $notice = $channel['label'] . '分類對照儲存失敗：主大綱與分類大綱為必填。';
        } else {
            $row = [
                'id' => $mappingId !== '' ? $mappingId : uid('map_' . $platform . '_'),
                'platform' => $platform,
                'source_group' => $sourceGroup,
                'source_type' => $sourceType,
                'source_brand' => $sourceBrand,
                'source_spec' => $sourceSpec,
                'platform_category_id' => trim((string)($_POST['platform_category_id'] ?? '')),
                'platform_category_path' => $platformPath !== '' ? $platformPath : '未歸檔',
                'mall_category_path' => trim((string)($_POST['mall_category_path'] ?? '')),
                'status' => ($platformPath === '' || $platformPath === '未歸檔') ? '未歸檔' : '已對照',
                'updated_by' => current_operator(),
                'updated_at' => date('c'),
            ];
            $updated = false;
            foreach ($marketplaceCategoryMappings as &$mapping) {
                if (($mapping['id'] ?? '') === $row['id']) {
                    if ($row['mall_category_path'] === '') $row['mall_category_path'] = (string)($mapping['mall_category_path'] ?? '');
                    $mapping = array_merge($mapping, $row);
                    $updated = true;
                    break;
                }
            }
            unset($mapping);
            if (!$updated) {
                $row['created_at'] = date('c');
                $marketplaceCategoryMappings[] = $row;
            }
            write_data('marketplace_category_mappings', $marketplaceCategoryMappings);
            $notice = ($row['status'] === '未歸檔')
                ? ($channel['label'] . '分類已列為未歸檔，可之後自己帶入。')
                : ($channel['label'] . '分類對照已儲存。');
        }
    }

    if ($action === 'delete_marketplace_category_mapping') {
        $mappingId = trim((string)($_POST['mapping_id'] ?? ''));
        $marketplaceCategoryMappings = array_values(array_filter($marketplaceCategoryMappings, function($row) use ($mappingId) {
            return ($row['id'] ?? '') !== $mappingId;
        }));
        write_data('marketplace_category_mappings', $marketplaceCategoryMappings);
        $notice = '分類對照草稿已刪除。';
    }

    $buildMarketplaceDraftRow = function ($product, $productId, $platform, $existing = []) {
        $available = stock_available($product);
        return [
            'platform' => $platform,
            'product_id' => (string)($product['id'] ?? $productId),
            'title' => trim((string)($_POST['listing_title'] ?? ($existing['title'] ?? ($product['title'] ?? '')))),
            'price' => max(0, (float)($_POST['listing_price'] ?? ($existing['price'] ?? 0))),
            'stock' => marketplace_capped_qty($product, $_POST['listing_stock'] ?? ($existing['stock'] ?? $available)),
            'weight_kg' => max(0, (float)($_POST['weight_kg'] ?? ($existing['weight_kg'] ?? 0))),
            'description' => trim((string)($_POST['listing_description'] ?? ($existing['description'] ?? ''))),
            'platform_category_id' => trim((string)($_POST['platform_category_id'] ?? ($existing['platform_category_id'] ?? ''))),
            'platform_item_id' => trim((string)($existing['platform_item_id'] ?? '')),
            'platform_url' => trim((string)($existing['platform_url'] ?? '')),
            'status' => trim((string)($existing['status'] ?? '草稿')) ?: '草稿',
            'updated_by' => current_operator(),
            'updated_at' => date('c'),
        ];
    };

    if ($action === 'save_shopee_listing_draft' || $action === 'save_marketplace_listing_draft') {
        $platform = marketplace_current_platform();
        $channel = marketplace_channel($platform);
        $productId = trim((string)($_POST['product_id'] ?? ''));
        $product = product_by_key($products, $productId);
        if (!$product) {
            $notice = $channel['label'] . '上架草稿儲存失敗：找不到產品。';
        } else {
            $existing = marketplace_find_draft($marketplaceListingDrafts, $platform, (string)($product['id'] ?? $productId));
            $row = $buildMarketplaceDraftRow($product, $productId, $platform, $existing);
            if (($row['status'] ?? '') === '已刊登' && marketplace_is_authorized($platform) === false && $platform !== 'yahoo_auction_tw') {
                $row['status'] = $existing['status'] ?? '草稿';
            }
            marketplace_upsert_draft($marketplaceListingDrafts, $row);
            write_data('marketplace_listing_drafts', $marketplaceListingDrafts);
            $notice = $channel['label'] . '商品草稿已儲存，尚未送到平台。';
        }
    }

    if ($action === 'save_marketplace_listing_link') {
        $platform = marketplace_current_platform();
        $channel = marketplace_channel($platform);
        $productId = trim((string)($_POST['product_id'] ?? ''));
        $product = product_by_key($products, $productId);
        $itemId = trim((string)($_POST['platform_item_id'] ?? ''));
        $itemUrl = trim((string)($_POST['platform_url'] ?? ''));
        if (!$product) {
            $notice = '回填平台編號失敗：找不到產品。';
        } else {
            $existing = marketplace_find_draft($marketplaceListingDrafts, $platform, (string)($product['id'] ?? $productId));
            $row = $buildMarketplaceDraftRow($product, $productId, $platform, $existing);
            $row['platform_item_id'] = $itemId;
            $row['platform_url'] = $itemUrl;
            if ($itemId !== '' || $itemUrl !== '') {
                $row['status'] = '已刊登';
                $row['last_synced_at'] = date('c');
            }
            marketplace_upsert_draft($marketplaceListingDrafts, $row);
            write_data('marketplace_listing_drafts', $marketplaceListingDrafts);
            $notice = $channel['label'] . '平台商品編號已回填。';
        }
    }

    if ($action === 'publish_marketplace_listing') {
        $platform = marketplace_current_platform();
        $channel = marketplace_channel($platform);
        $productId = trim((string)($_POST['product_id'] ?? ''));
        $product = product_by_key($products, $productId);
        if (!$product) {
            $notice = $channel['label'] . '上架失敗：找不到產品。';
        } else {
            $existing = marketplace_find_draft($marketplaceListingDrafts, $platform, (string)($product['id'] ?? $productId));
            $row = $buildMarketplaceDraftRow($product, $productId, $platform, $existing);
            $mapping = marketplace_find_mapping($marketplaceCategoryMappings, $platform, $product);
            $result = marketplace_publish($platform, $product, $row, $mapping);
            marketplace_upsert_draft($marketplaceListingDrafts, $result['draft']);
            write_data('marketplace_listing_drafts', $marketplaceListingDrafts);
            $notice = $result['ok']
                ? ($channel['label'] . '已送出上架／同步。')
                : ($channel['label'] . '上架未完成：' . $result['error']);
        }
    }

    if ($action === 'sync_marketplace_stock') {
        $platform = marketplace_current_platform();
        $channel = marketplace_channel($platform);
        $productId = trim((string)($_POST['product_id'] ?? ''));
        $product = product_by_key($products, $productId);
        if (!$product) {
            $notice = '同步庫存失敗：找不到產品。';
        } else {
            $existing = marketplace_find_draft($marketplaceListingDrafts, $platform, (string)($product['id'] ?? $productId));
            $row = $buildMarketplaceDraftRow($product, $productId, $platform, $existing);
            $row['stock'] = stock_available($product);
            $result = marketplace_sync_stock($platform, $product, $row);
            marketplace_upsert_draft($marketplaceListingDrafts, $result['draft']);
            write_data('marketplace_listing_drafts', $marketplaceListingDrafts);
            $notice = $result['ok']
                ? ($channel['label'] . '庫存已依後台可用量同步。')
                : ($channel['label'] . '庫存同步失敗：' . $result['error']);
        }
    }

    if ($action === 'export_yahoo_auction_csv') {
        $selected = $_POST['yahoo_export_ids'] ?? [];
        if (!is_array($selected)) $selected = [];
        $selected = array_values(array_filter(array_map('strval', $selected)));
        $rows = [];
        foreach ($products as $product) {
            $pid = (string)($product['id'] ?? '');
            if ($pid === '') continue;
            if ($selected && !in_array($pid, $selected, true)) continue;
            $draft = marketplace_find_draft($marketplaceListingDrafts, 'yahoo_auction_tw', $pid);
            if (!$draft && $selected) {
                $draft = [
                    'title' => $product['title'] ?? '',
                    'price' => $product['selling_price'] ?? ($product['start_price'] ?? 0),
                    'stock' => stock_available($product),
                    'description' => $product['description'] ?? '',
                ];
            }
            if (!$draft) continue;
            $mapping = marketplace_find_mapping($marketplaceCategoryMappings, 'yahoo_auction_tw', $product);
            $rows[] = marketplace_yahoo_csv_row($product, $draft, $mapping);
        }
        if (!$rows) {
            $notice = '沒有可匯出的奇摩拍賣草稿。請先存草稿或勾選產品。';
        } else {
            $csv = marketplace_yahoo_csv($rows);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="yahoo-auction-bulk-' . date('Ymd-His') . '.csv"');
            echo $csv;
            exit;
        }
    }

    if ($action === 'save_marketplace_credentials') {
        $posted = [
            'shopee_tw' => [
                'partner_id' => $_POST['shopee_partner_id'] ?? '',
                'partner_key' => $_POST['shopee_partner_key'] ?? '',
                'shop_id' => $_POST['shopee_shop_id'] ?? '',
                'access_token' => $_POST['shopee_access_token'] ?? '',
                'refresh_token' => $_POST['shopee_refresh_token'] ?? '',
            ],
            'ruten_tw' => [
                'api_key' => $_POST['ruten_api_key'] ?? '',
                'secret_key' => $_POST['ruten_secret_key'] ?? '',
                'salt_key' => $_POST['ruten_salt_key'] ?? '',
                'store_class_id' => $_POST['ruten_store_class_id'] ?? '',
                'location' => $_POST['ruten_location'] ?? '',
            ],
        ];
        $saved = marketplace_save_credentials($posted);
        $notice = $saved['ok'] ? '通路憑證已儲存（只寫入伺服器 private 目錄）。' : ('憑證儲存失敗：' . $saved['error']);
    }

    if ($action === 'add_mall_catalog_candidate') {
        $productId = trim((string)($_POST['product_id'] ?? ''));
        $product = product_by_key($products, $productId);
        if (!$product) {
            $notice = '加入官網參考型錄候選失敗：找不到產品。';
        } else {
            $exists = false;
            foreach ($marketplaceMallCandidates as $row) {
                if ((string)($row['product_id'] ?? '') === (string)($product['id'] ?? $productId)) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $marketplaceMallCandidates[] = [
                    'id' => uid('mallcand_'),
                    'product_id' => (string)($product['id'] ?? $productId),
                    'barcode' => (string)($product['barcode'] ?? ''),
                    'sku' => trim((string)($_POST['candidate_sku'] ?? ($product['barcode'] ?? ($product['id'] ?? '')))),
                    'title' => (string)($product['title'] ?? ''),
                    'note' => '僅列入候選，不自動改參考價',
                    'created_by' => current_operator(),
                    'created_at' => date('c'),
                ];
                write_data('marketplace_mall_candidates', $marketplaceMallCandidates);
            }
            $notice = '已列入官網參考型錄候選，不會自動改參考價。';
        }
    }

    if ($action === 'save_color_module') {
        $moduleId = trim($_POST['color_module_id'] ?? '');
        $name = trim($_POST['color_module_name'] ?? '');
        $colorsText = str_replace(["\r\n", "\r"], "\n", trim($_POST['color_module_colors'] ?? ''));
        $sizesText = str_replace(["\r\n", "\r"], "\n", trim($_POST['color_module_sizes'] ?? ''));
        $ajax = !empty($_POST['ajax']);
        $respondColorModule = function ($ok, $message, $module = null) use ($ajax) {
            if (!$ajax) return;
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok, 'error' => $ok ? '' : $message, 'notice' => $message, 'module' => $module], JSON_UNESCAPED_UNICODE);
            exit;
        };
        $splitList = function ($text) {
            $items = preg_split('/[\n,，\/]+/u', (string)$text);
            $items = array_values(array_unique(array_filter(array_map('trim', $items), function($v) { return $v !== ''; })));
            return $items;
        };
        $colors = $splitList($colorsText);
        $sizes = $splitList($sizesText);
        if ($moduleId === 'cm_clothes_shared') {
            $notice = '服裝部共用碼表是系統模組，請另外新增面板，不要改這份碼表。';
            $respondColorModule(false, $notice);
        } elseif ($name === '' || (!$colors && !$sizes)) {
            $notice = '顏色尺碼模組儲存失敗：請輸入類別名稱，並至少填入顏色或尺寸。';
            $respondColorModule(false, $notice);
        } else {
            $row = [
                'id' => $moduleId !== '' ? $moduleId : uid('cm_'),
                'name' => $name,
                'colors' => $colors,
                'sizes' => $sizes,
                'updated_at' => date('c'),
            ];
            $found = false;
            foreach ($colorModules as &$module) {
                if (($module['id'] ?? '') === $row['id']) {
                    $module = array_merge($module, $row);
                    $found = true;
                    break;
                }
            }
            unset($module);
            if (!$found) {
                $row['created_at'] = date('c');
                $colorModules[] = $row;
            }
            write_data('color_modules', $colorModules);
            $notice = '顏色尺碼模組已儲存。';
            $respondColorModule(true, $notice, $row);
        }
    }

    if ($action === 'delete_color_module') {
        $moduleId = trim($_POST['color_module_id'] ?? '');
        if ($moduleId === 'cm_clothes_shared') {
            $notice = '服裝部共用碼表是系統模組，不能刪除。';
        } else {
            $colorModules = array_values(array_filter($colorModules, function($m) use ($moduleId) { return ($m['id'] ?? '') !== $moduleId; }));
            write_data('color_modules', $colorModules);
            $notice = '顏色尺碼模組已刪除。';
        }
    }

    if ($action === 'delete_color_modules') {
        $ids = array_values(array_filter(array_map('trim', (array)($_POST['color_module_ids'] ?? [])), function($id) {
            return $id !== '' && $id !== 'cm_clothes_shared';
        }));
        if (!$ids) {
            $notice = '請先勾選要刪除的顏色尺碼模組。';
        } else {
            $colorModules = array_values(array_filter($colorModules, function($m) use ($ids) {
                return !in_array(($m['id'] ?? ''), $ids, true);
            }));
            write_data('color_modules', $colorModules);
            $notice = '已刪除勾選的顏色尺碼模組。';
        }
    }

    if ($action === 'save_product_category_option') {
        $ajax = !empty($_POST['ajax']);
        $respondOption = function ($ok, $message, $extra = []) use ($ajax) {
            if (!$ajax) return;
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array_merge(['ok' => $ok, 'error' => $ok ? '' : $message, 'notice' => $message], $extra), JSON_UNESCAPED_UNICODE);
            exit;
        };
        $group = trim((string)($_POST['category_group'] ?? ''));
        $type = trim((string)($_POST['category_type'] ?? ''));
        $brand = trim((string)($_POST['category_brand'] ?? ''));
        $spec = trim((string)($_POST['category_spec'] ?? ''));
        if ($group === '') $group = trim((string)($_POST['category_group'] ?? '')) ?: '組裝硬體';
        if ($type === '') {
            $notice = '請先輸入分類大綱再儲存。';
            $respondOption(false, $notice);
        } else {
            $rule = ensure_product_category_rule($productCategories, $group, $type, $brand, $spec);
            $notice = $rule
                ? ('已記住「' . $type . ($brand !== '' ? ' / ' . $brand : '') . ($spec !== '' ? ' / ' . $spec : '') . '」，下次可直接選。')
                : '選單紀錄儲存失敗。';
            $respondOption((bool)$rule, $notice, [
                'rule' => $rule,
                'type_code' => $rule['type_code'] ?? '',
                'next_serial' => next_type_serial($products, $rule['type_code'] ?? ''),
            ]);
        }
    }

    if ($action === 'save_product_spec') {
        $value = trim((string)($_POST['spec'] ?? ''));
        $ajax = !empty($_POST['ajax']);
        $respondSpec = function ($ok, $message, $specs = null) use ($ajax) {
            if (!$ajax) return;
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok, 'error' => $ok ? '' : $message, 'notice' => $message, 'specs' => $specs], JSON_UNESCAPED_UNICODE);
            exit;
        };
        if ($value === '') {
            $notice = '請先輸入規格再儲存。';
            $respondSpec(false, $notice);
        } elseif (!remember_product_spec($productSpecs, $value)) {
            $notice = '這個規格已經在清單裡，可直接挑選。';
            $respondSpec(true, $notice, $productSpecs);
        } else {
            write_data('product_specs', $productSpecs);
            $notice = '規格已加入清單，之後可直接挑選。';
            $respondSpec(true, $notice, $productSpecs);
        }
    }

    if ($action === 'save_purchase_source') {
        $value = trim((string)($_POST['purchase_source'] ?? ''));
        $ajax = !empty($_POST['ajax']);
        $respondSource = function ($ok, $message, $extra = []) use ($ajax) {
            if (!$ajax) return;
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $ok, 'error' => $ok ? '' : $message, 'notice' => $message] + $extra, JSON_UNESCAPED_UNICODE);
            exit;
        };
        if ($value === '') {
            $notice = '請先輸入或挑選供應來源。';
            $respondSource(false, $notice);
        } else {
            $supplier = ensure_named_supplier($suppliers, $value);
            $purchaseSourceOptions = purchase_source_options($suppliers, $products);
            $notice = $value === '其他'
                ? '已選「其他」，不列入廠商建檔。'
                : ($supplier ? ('已自動儲存「' . ($supplier['name'] ?? $value) . '」，廠商搜尋可依分類找到。') : '供應來源已記住。');
            $respondSource(true, $notice, [
                'sources' => $purchaseSourceOptions,
                'supplier' => $supplier,
                'supplier_category' => $supplier ? supplier_search_category($supplier) : '',
            ]);
        }
    }

    if ($action === 'save_warehouse_item') {
        $type = trim($_POST['warehouse_item_type'] ?? '');
        $department = trim($_POST['warehouse_item_department'] ?? '電腦部門');
        $name = trim($_POST['warehouse_item_name'] ?? '');
        $warehouse = trim($_POST['warehouse_item_warehouse'] ?? '');
        $shelf = normalize_shelf_code($_POST['warehouse_item_shelf'] ?? '');
        $layer = trim($_POST['warehouse_item_layer'] ?? '');
        $pair = trim($_POST['warehouse_shelf_pair'] ?? '');
        if ($pair !== '' && ($warehouse === '' || $shelf === '')) {
            $parts = explode('||', $pair);
            if (count($parts) >= 3) {
                $department = trim($parts[0] ?? $department);
                $warehouse = trim($parts[1] ?? $warehouse);
                $shelf = trim($parts[2] ?? $shelf);
            } else {
                $warehouse = trim($parts[0] ?? $warehouse);
                $shelf = trim($parts[1] ?? $shelf);
            }
        }
        if ($type === 'shelf') $name = normalize_shelf_code($name);
        if ($type === 'combo' && !in_array($layer, ['上層', '下層'], true)) {
            $notice = '新增失敗：倉架位置只能選擇上層或下層。';
        } elseif ($type === 'shelf') {
            if ($warehouse === '' || $name === '') {
                $notice = '新增失敗：請先選倉別，再輸入倉架名稱。';
            } else {
                if ($department === '') $department = warehouse_department_for($warehouse);
                $added = ensure_warehouse_shelf($warehouses, $department, $warehouse, $name);
                if ($added) write_data('warehouses', $warehouses);
                $notice = $added ? ('已新增貨架：' . $name) : '此貨架已存在，可直接選用。';
            }
        } elseif (!in_array($type, ['warehouse', 'combo'], true) || ($name === '' && $type !== 'combo')) {
            $notice = '新增失敗：請輸入貨倉管理資料。';
        } else {
            if ($type === 'warehouse') $warehouse = $name;
            if ($department === '') $department = warehouse_department_for($warehouse);
            $row = [
                'id' => uid('wh_'),
                'department' => $department,
                'type' => $type,
                'name' => $type === 'combo' ? trim($department . ' / ' . $warehouse . ' / ' . $shelf . ' / ' . $layer, ' /') : $name,
                'warehouse' => $warehouse,
                'shelf' => $shelf,
                'layer' => $layer,
                'created_at' => date('c'),
            ];
            $exists = false;
            foreach ($warehouses as $w) {
                $sameWarehouse = $type === 'warehouse' || trim((string)($w['warehouse'] ?? '')) === $warehouse;
                if (($w['department'] ?? '') === $row['department'] && ($w['type'] ?? '') === $row['type'] && ($w['name'] ?? '') === $row['name'] && $sameWarehouse) $exists = true;
            }
            if (!$exists && $row['name'] !== '') {
                $warehouses[] = $row;
                write_data('warehouses', $warehouses);
                $notice = '已新增貨倉設定：' . $row['name'];
            } else {
                $notice = '此貨倉設定已存在或資料不完整。';
            }
        }
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => strpos((string)$notice, '新增失敗') !== 0,
                'message' => $notice,
                'shelf' => $type === 'shelf' ? $name : $shelf,
                'warehouse' => $warehouse,
                'department' => $department,
                'tree' => warehouse_tree($warehouses),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }


    if ($action === 'save_ops_staff_permission') {
        $account = trim((string)($_POST['staff_account'] ?? ''));
        $name = trim((string)($_POST['staff_name'] ?? ''));
        $role = trim((string)($_POST['staff_role'] ?? ''));
        $mainEmployeeMap = [];
        foreach ($mainEmployees as $employeeRow) {
            $mainAccount = trim((string)($employeeRow['account'] ?? ''));
            if ($mainAccount !== '') $mainEmployeeMap[mb_strtolower($mainAccount, 'UTF-8')] = $employeeRow;
        }
        $mainEmployee = $mainEmployeeMap[mb_strtolower($account, 'UTF-8')] ?? null;
        if (is_array($mainEmployee)) {
            $name = trim((string)($mainEmployee['name'] ?? $name));
        }
        $department = is_array($mainEmployee) ? trim((string)($mainEmployee['department'] ?? '')) : '';
        $allowed = array_values(array_intersect(array_keys($opsFunctionMap), (array)($_POST['allowed_tabs'] ?? [])));
        if (!in_array('overview', $allowed, true)) $allowed[] = 'overview';
        if ($account !== '') {
            $found = false;
            foreach ($opsStaffPermissions as &$row) {
                if (strcasecmp((string)($row['account'] ?? ''), $account) === 0) {
                    $row['name'] = $name;
                    $row['department'] = $department;
                    $row['role'] = $role;
                    $row['allowed_tabs'] = $allowed;
                    $row['updated_by'] = current_operator();
                    $row['updated_at'] = date('c');
                    $found = true;
                    break;
                }
            }
            unset($row);
            if (!$found) {
                $opsStaffPermissions[] = [
                    'id' => uid('staff_'),
                    'account' => $account,
                    'name' => $name,
                    'department' => $department,
                    'role' => $role,
                    'allowed_tabs' => $allowed,
                    'source' => is_array($mainEmployee) ? 'baohui_employee' : 'manual',
                    'created_by' => current_operator(),
                    'created_at' => date('c'),
                ];
            }
            write_data('ops_staff_permissions', $opsStaffPermissions);
            $notice = '員工電商營運功能權限已儲存：' . $account;
        } else {
            $notice = '請先輸入員工登入帳號。';
        }
    }

    if ($action === 'delete_ops_staff_permission') {
        $id = trim((string)($_POST['staff_id'] ?? ''));
        $opsStaffPermissions = array_values(array_filter($opsStaffPermissions, function($row) use ($id) {
            return (string)($row['id'] ?? '') !== $id;
        }));
        write_data('ops_staff_permissions', $opsStaffPermissions);
        $notice = '員工電商營運功能權限已刪除。';
    }

    if ($action === 'save_document_workflow') {
        $id = trim((string)($_POST['workflow_id'] ?? ''));
        $type = trim((string)($_POST['workflow_type'] ?? ''));
        $target = trim((string)($_POST['workflow_target'] ?? ''));
        $amount = (float)($_POST['workflow_amount'] ?? 0);
        $date = trim((string)($_POST['workflow_date'] ?? date('Y-m-d')));
        $summary = trim((string)($_POST['workflow_summary'] ?? ''));
        $linesText = trim((string)($_POST['workflow_lines'] ?? ''));
        $submitNow = !empty($_POST['submit_for_review']);
        if ($type === '' || $target === '') {
            $notice = '單據流程建立失敗：請選擇單據類型並輸入對象。';
        } else {
            $row = [
                'id' => $id !== '' ? $id : uid('dw_'),
                'workflow_no' => '',
                'document_type' => $type,
                'target' => $target,
                'amount' => $amount,
                'document_date' => $date !== '' ? $date : date('Y-m-d'),
                'summary' => $summary,
                'lines_text' => $linesText,
                'status' => $submitNow ? '待審核' : '草稿',
                'operator' => current_operator(),
                'updated_at' => date('c'),
            ];
            $found = false;
            foreach ($documentWorkflows as &$workflow) {
                if (($workflow['id'] ?? '') === $row['id']) {
                    $row['workflow_no'] = $workflow['workflow_no'] ?? next_document_workflow_no($documentWorkflows);
                    $row['created_at'] = $workflow['created_at'] ?? date('c');
                    $row['history'] = $workflow['history'] ?? [];
                    $row['history'][] = ['time'=>date('c'),'operator'=>current_operator(),'action'=>$submitNow?'儲存並送審':'儲存草稿','note'=>''];
                    $workflow = array_merge($workflow, $row);
                    $found = true;
                    break;
                }
            }
            unset($workflow);
            if (!$found) {
                $row['workflow_no'] = next_document_workflow_no($documentWorkflows);
                $row['created_at'] = date('c');
                $row['history'] = [['time'=>date('c'),'operator'=>current_operator(),'action'=>$submitNow?'建立並送審':'建立草稿','note'=>'']];
                $documentWorkflows[] = $row;
            }
            write_data('document_workflows', $documentWorkflows);
            $notice = '單據流程已儲存：' . $row['workflow_no'];
        }
    }

    if (in_array($action, ['submit_document_workflow','approve_document_workflow','convert_document_workflow','void_document_workflow','offset_document_workflow'], true)) {
        $workflowId = trim((string)($_POST['workflow_id'] ?? ''));
        $workflowNote = trim((string)($_POST['workflow_note'] ?? ''));
        $workflowIndex = -1;
        foreach ($documentWorkflows as $i => $workflow) {
            if (($workflow['id'] ?? '') === $workflowId) { $workflowIndex = $i; break; }
        }
        if ($workflowIndex < 0) {
            $notice = '單據流程操作失敗：找不到流程單。';
        } else {
            $workflow = $documentWorkflows[$workflowIndex];
            $now = date('c');
            $historyAction = '';
            if ($action === 'submit_document_workflow') {
                $workflow['status'] = '待審核';
                $historyAction = '送審';
                $notice = '單據已送審：' . ($workflow['workflow_no'] ?? '');
            } elseif ($action === 'approve_document_workflow') {
                $workflow['status'] = '已核准';
                $workflow['approved_by'] = current_operator();
                $workflow['approved_at'] = $now;
                $historyAction = '審核核准';
                $notice = '單據已核准：' . ($workflow['workflow_no'] ?? '');
            } elseif ($action === 'convert_document_workflow') {
                $type = ops_document_type_label($workflow['document_type'] ?? '流程單據');
                $refNo = '';
                if ($type === '收款單') {
                    $refNo = 'CR-' . date('Ymd-His');
                    $collectionReceipts[] = [
                        'id' => uid('cr_'),
                        'receipt_no' => $refNo,
                        'receipt_date' => $workflow['document_date'] ?? date('Y-m-d'),
                        'customer_name' => $workflow['target'] ?? '',
                        'amount' => (float)($workflow['amount'] ?? 0),
                        'status' => '已確認',
                        'note' => trim(($workflow['summary'] ?? '') . "\n" . ($workflow['lines_text'] ?? '')),
                        'source_workflow_id' => $workflow['id'] ?? '',
                        'created_at' => $now,
                        'operator' => current_operator(),
                    ];
                    write_data('collection_receipts', $collectionReceipts);
                } elseif ($type === '請款單') {
                    $refNo = next_billing_request_no($billingRequests);
                    $billingRequests[] = [
                        'id' => uid('ar_'),
                        'request_no' => $refNo,
                        'request_date' => $workflow['document_date'] ?? date('Y-m-d'),
                        'customer_name' => $workflow['target'] ?? '',
                        'total_amount' => (float)($workflow['amount'] ?? 0),
                        'status' => '待請款',
                        'note' => trim(($workflow['summary'] ?? '') . "\n" . ($workflow['lines_text'] ?? '')),
                        'items' => [],
                        'source_workflow_id' => $workflow['id'] ?? '',
                        'created_at' => $now,
                        'operator' => current_operator(),
                    ];
                    write_data('billing_requests', $billingRequests);
                } elseif ($type === '調撥單據' || $type === '調撥單') {
                    $refNo = next_inventory_doc_no('TRF', $inventoryTransfers);
                    $inventoryTransfers[] = [
                        'id' => uid('trf_'),
                        'doc_no' => $refNo,
                        'date' => $workflow['document_date'] ?? date('Y-m-d'),
                        'from_warehouse' => '',
                        'to_warehouse' => $workflow['target'] ?? '',
                        'status' => '待調撥',
                        'note' => trim(($workflow['summary'] ?? '') . "\n" . ($workflow['lines_text'] ?? '')),
                        'lines' => [],
                        'source_workflow_id' => $workflow['id'] ?? '',
                        'created_at' => $now,
                        'operator' => current_operator(),
                    ];
                    write_data('inventory_transfers', $inventoryTransfers);
                } else {
                    $prefixKind = in_array($type, ['進貨入庫單','進貨單據'], true) ? 'JH' : (in_array($type, ['銷售出庫單','銷售單據'], true) ? 'SELL' : (in_array($type, ['進貨退貨單','進貨退回'], true) ? 'BACK-F' : (in_array($type, ['銷售退貨單','銷貨退回','銷貨退貨單'], true) ? 'BACK-C' : 'DOC')));
                    if ($prefixKind === 'SELL') $refNo = ops_next_doc_no('sell', array_merge(ops_collect_doc_nos($stockMovements), ops_collect_doc_nos($deliveryNotes)));
                    elseif ($prefixKind === 'BACK-F') $refNo = ops_next_doc_no('back_f', array_merge(ops_collect_doc_nos($stockMovements), ops_collect_doc_nos($returns)));
                    elseif ($prefixKind === 'BACK-C') $refNo = ops_next_doc_no('back_c', array_merge(ops_collect_doc_nos($stockMovements), ops_collect_doc_nos($returns)));
                    else $refNo = next_inventory_doc_no($prefixKind, $stockMovements);
                    $stockMovements[] = [
                        'id' => uid('doc_'),
                        'document_no' => $refNo,
                        'source_doc_no' => $workflow['workflow_no'] ?? '',
                        'source_doc_type' => $type,
                        'date' => $workflow['document_date'] ?? date('Y-m-d'),
                        'document_date' => $workflow['document_date'] ?? date('Y-m-d'),
                        'party_name' => $workflow['target'] ?? '',
                        'amount' => (float)($workflow['amount'] ?? 0),
                        'total_amount' => (float)($workflow['amount'] ?? 0),
                        'status' => '已轉正式',
                        'summary' => trim(($workflow['summary'] ?? '') . "\n" . ($workflow['lines_text'] ?? '')),
                        'source' => '單據流程中心',
                        'source_workflow_id' => $workflow['id'] ?? '',
                        'operator' => current_operator(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    write_data('stock_movements', $stockMovements);
                }
                $workflow['status'] = '已轉正式';
                $workflow['formal_document_no'] = $refNo;
                $workflow['converted_by'] = current_operator();
                $workflow['converted_at'] = $now;
                $historyAction = '轉正式單據 ' . $refNo;
                $notice = '已轉正式單據：' . $refNo;
            } elseif ($action === 'void_document_workflow') {
                $workflow['status'] = '已作廢';
                $workflow['void_by'] = current_operator();
                $workflow['void_at'] = $now;
                $workflow['void_reason'] = $workflowNote;
                $historyAction = '作廢';
                $notice = '單據已作廢：' . ($workflow['workflow_no'] ?? '');
            } elseif ($action === 'offset_document_workflow') {
                $amount = (float)($workflow['amount'] ?? 0);
                $offsetNo = next_inventory_doc_no('OFF', $stockMovements);
                $stockMovements[] = [
                    'id' => uid('off_'),
                    'document_no' => $offsetNo,
                    'source_doc_no' => $workflow['formal_document_no'] ?? ($workflow['workflow_no'] ?? ''),
                    'source_doc_type' => '沖帳單',
                    'date' => date('Y-m-d'),
                    'document_date' => date('Y-m-d'),
                    'party_name' => $workflow['target'] ?? '',
                    'amount' => -1 * $amount,
                    'total_amount' => -1 * $amount,
                    'status' => '已沖帳',
                    'summary' => '沖帳來源：' . ($workflow['formal_document_no'] ?? ($workflow['workflow_no'] ?? '')) . ($workflowNote !== '' ? "\n原因：" . $workflowNote : ''),
                    'source' => '單據流程中心',
                    'source_workflow_id' => $workflow['id'] ?? '',
                    'operator' => current_operator(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                write_data('stock_movements', $stockMovements);
                $workflow['status'] = '已沖帳';
                $workflow['offset_document_no'] = $offsetNo;
                $workflow['offset_by'] = current_operator();
                $workflow['offset_at'] = $now;
                $workflow['offset_reason'] = $workflowNote;
                $historyAction = '沖帳 ' . $offsetNo;
                $notice = '已建立沖帳單：' . $offsetNo;
            }
            if (!isset($workflow['history']) || !is_array($workflow['history'])) $workflow['history'] = [];
            $workflow['history'][] = ['time'=>$now,'operator'=>current_operator(),'action'=>$historyAction,'note'=>$workflowNote];
            $workflow['updated_at'] = $now;
            $documentWorkflows[$workflowIndex] = $workflow;
            write_data('document_workflows', $documentWorkflows);
        }
    }


    if (in_array($action, ['approve_inventory_count', 'return_inventory_count'], true)) {
        $countId = trim((string)($_POST['count_id'] ?? ''));
        $approvalNote = trim((string)($_POST['approval_note'] ?? ''));
        $countIndex = -1;
        foreach ($inventoryCounts as $index => $countDoc) {
            if (($countDoc['id'] ?? '') === $countId) {
                $countIndex = $index;
                break;
            }
        }
        if (!baohui_can_approve_inventory_count()) {
            $notice = '只有管理者可以確認或退回盤點差異。';
        } elseif ($countIndex < 0) {
            $notice = '找不到指定的盤點單。';
        } elseif (($inventoryCounts[$countIndex]['status'] ?? '') === '已確認並調整') {
            $notice = '此盤點單已確認並完成庫存調整，不能重複執行。';
        } elseif ($action === 'return_inventory_count') {
            $inventoryCounts[$countIndex]['status'] = '退回重盤';
            $inventoryCounts[$countIndex]['approval_status'] = '退回重盤';
            $inventoryCounts[$countIndex]['approval_note'] = $approvalNote;
            $inventoryCounts[$countIndex]['reviewed_by'] = current_operator();
            $inventoryCounts[$countIndex]['reviewed_at'] = date('c');
            write_data('inventory_counts', $inventoryCounts);
            $notice = '盤點單已退回重盤，庫存沒有變更：' . ($inventoryCounts[$countIndex]['doc_no'] ?? '');
        } else {
            $countDoc = $inventoryCounts[$countIndex];
            $pendingAdjustments = [];
            $missingProducts = [];
            foreach (($countDoc['lines'] ?? []) as $lineIndex => $line) {
                $productKey = ($line['product_id'] ?? '') ?: (($line['barcode'] ?? '') ?: ($line['scan_code'] ?? ''));
                $productIndex = find_product_key($products, $productKey);
                if ($productIndex < 0) {
                    $missingProducts[] = $productKey;
                    continue;
                }
                $actualQty = max(0, (int)($line['actual_qty'] ?? $line['qty'] ?? 0));
                $currentQty = max(0, (int)($products[$productIndex]['stock_total'] ?? $products[$productIndex]['stock'] ?? 0));
                $delta = $actualQty - $currentQty;
                $pendingAdjustments[] = [
                    'line_index' => $lineIndex,
                    'product_index' => $productIndex,
                    'actual_qty' => $actualQty,
                    'current_qty' => $currentQty,
                    'delta' => $delta,
                ];
            }
            if ($missingProducts) {
                $notice = '盤點確認失敗：下列產品已不存在，請先確認：' . implode('、', array_filter($missingProducts));
            } else {
                $adjustmentIds = [];
                foreach ($pendingAdjustments as $pending) {
                    $lineIndex = $pending['line_index'];
                    $productIndex = $pending['product_index'];
                    $beforeQty = $pending['current_qty'];
                    $afterQty = $pending['actual_qty'];
                    $delta = $pending['delta'];
                    $countDoc['lines'][$lineIndex]['approval_system_qty'] = $beforeQty;
                    $countDoc['lines'][$lineIndex]['approval_diff_qty'] = $delta;
                    if ($delta === 0) continue;

                    $type = $delta < 0 ? 'loss' : 'overage';
                    $products[$productIndex]['stock_total'] = $afterQty;
                    $products[$productIndex]['updated_at'] = date('c');
                    $products[$productIndex]['updated_by'] = current_operator();
                    $adjustment = [
                        'id' => uid($type === 'loss' ? 'los_' : 'ovg_'),
                        'doc_no' => next_inventory_doc_no($type === 'loss' ? 'LOS' : 'OVG', $inventoryAdjustments),
                        'type' => $type,
                        'date' => date('Y-m-d'),
                        'product_id' => $products[$productIndex]['id'] ?? '',
                        'barcode' => $products[$productIndex]['barcode'] ?? '',
                        'title' => $products[$productIndex]['title'] ?? '',
                        'qty' => abs($delta),
                        'before_qty' => $beforeQty,
                        'after_qty' => $afterQty,
                        'unit_cost' => (float)($products[$productIndex]['cost'] ?? 0),
                        'reason' => '盤點差異確認：' . ($countDoc['doc_no'] ?? '') . ($approvalNote !== '' ? '；' . $approvalNote : ''),
                        'status' => '管理者已確認',
                        'source_count_id' => $countDoc['id'] ?? '',
                        'source_count_no' => $countDoc['doc_no'] ?? '',
                        'operator' => current_operator(),
                        'created_at' => date('c'),
                    ];
                    $inventoryAdjustments[] = $adjustment;
                    $adjustmentIds[] = $adjustment['id'];
                    $stockMovements[] = [
                        'id' => uid('mv_'),
                        'document_no' => $adjustment['doc_no'],
                        'product_id' => $adjustment['product_id'],
                        'barcode' => $adjustment['barcode'],
                        'type' => $type === 'loss' ? '報損' : '報溢',
                        'qty' => $delta,
                        'unit_cost' => $adjustment['unit_cost'],
                        'note' => $adjustment['reason'],
                        'source_count_id' => $countDoc['id'] ?? '',
                        'source_count_no' => $countDoc['doc_no'] ?? '',
                        'operator' => current_operator(),
                        'created_at' => date('c'),
                    ];
                }
                $countDoc['status'] = '已確認並調整';
                $countDoc['approval_status'] = '已確認';
                $countDoc['approval_note'] = $approvalNote;
                $countDoc['approved_by'] = current_operator();
                $countDoc['approved_at'] = date('c');
                $countDoc['adjustment_ids'] = $adjustmentIds;
                $inventoryCounts[$countIndex] = $countDoc;
                write_data('products', $products);
                write_data('stock_movements', $stockMovements);
                write_data('inventory_adjustments', $inventoryAdjustments);
                write_data('inventory_counts', $inventoryCounts);
                $notice = '盤點差異已由管理者確認並帶出庫存調整：' . ($countDoc['doc_no'] ?? '') . '，調整 ' . count($adjustmentIds) . ' 筆。';
            }
        }
    }

    if ($action === 'save_inventory_count') {
        $countWarehouse = trim((string)($_POST['count_warehouse'] ?? ''));
        $lines = [];
        $invalidScans = [];
        $wrongWarehouseScans = [];
        foreach (scan_lines_from_text($_POST['count_lines'] ?? '') as $scan) {
            $line = scan_to_product_line($products, $scan);
            if (empty($line['found'])) {
                $invalidScans[] = $scan['code'] ?? '';
                continue;
            }
            if ($countWarehouse !== '' && trim((string)($line['warehouse_name'] ?? '')) !== $countWarehouse) {
                $wrongWarehouseScans[] = $scan['code'] ?? '';
                continue;
            }
            $line['actual_qty'] = $line['qty'];
            $lines[] = $line;
        }
        if ($countWarehouse === '') {
            $notice = '盤點單建立失敗：請先選擇盤點倉庫。';
        } elseif ($invalidScans) {
            $notice = '盤點單建立失敗：找不到條碼 ' . implode('、', array_filter($invalidScans)) . '。';
        } elseif ($wrongWarehouseScans) {
            $notice = '盤點單建立失敗：以下商品不屬於「' . $countWarehouse . '」：' . implode('、', array_filter($wrongWarehouseScans)) . '。';
        } elseif (!$lines) {
            $notice = '盤點單建立失敗：請至少輸入一筆條碼。';
        } else {
            $differenceCount = count(array_filter($lines, function($line) { return (int)($line['diff_qty'] ?? 0) !== 0; }));
            $doc = [
                'id' => uid('cnt_'),
                'doc_no' => next_inventory_doc_no('PDC', $inventoryCounts),
                'date' => $_POST['count_date'] ?: date('Y-m-d'),
                'status' => $differenceCount > 0 ? '待管理者確認' : '已完成',
                'approval_status' => $differenceCount > 0 ? '待管理者確認' : '無差異',
                'difference_count' => $differenceCount,
                'warehouse_name' => $countWarehouse,
                'note' => trim((string)($_POST['count_note'] ?? '')),
                'lines' => $lines,
                'operator' => current_operator(),
                'created_at' => date('c'),
            ];
            $inventoryCounts[] = $doc;
            write_data('inventory_counts', $inventoryCounts);
            $notice = '盤點單已建立：' . $doc['doc_no'] . '，共 ' . count($lines) . ' 筆；差異 ' . $differenceCount . ' 筆。' . ($differenceCount > 0 ? ' 已送交管理者確認，尚未調整庫存。' : '');
        }
    }

    if ($action === 'save_inventory_transfer') {
        $lines = [];
        foreach (scan_lines_from_text($_POST['transfer_lines'] ?? '') as $scan) {
            $lines[] = scan_to_product_line($products, $scan);
        }
        if (!$lines) {
            $notice = '調撥單建立失敗：請至少輸入一筆條碼。';
        } else {
            $doc = [
                'id' => uid('trf_'),
                'doc_no' => next_inventory_doc_no('TRF', $inventoryTransfers),
                'date' => date('Y-m-d'),
                'from_warehouse' => trim((string)($_POST['from_warehouse'] ?? '')),
                'from_shelf' => normalize_shelf_code($_POST['from_shelf'] ?? ''),
                'from_location' => normalize_warehouse_layer($_POST['from_location'] ?? '', $_POST['from_shelf'] ?? ''),
                'to_warehouse' => trim((string)($_POST['to_warehouse'] ?? '')),
                'to_shelf' => normalize_shelf_code($_POST['to_shelf'] ?? ''),
                'to_location' => normalize_warehouse_layer($_POST['to_location'] ?? '', $_POST['to_shelf'] ?? ''),
                'status' => $_POST['transfer_status'] ?? '待調撥',
                'note' => trim((string)($_POST['transfer_note'] ?? '')),
                'lines' => $lines,
                'operator' => current_operator(),
                'created_at' => date('c'),
            ];
            $inventoryTransfers[] = $doc;
            write_data('inventory_transfers', $inventoryTransfers);
            $notice = '調撥單已建立：' . $doc['doc_no'] . '，共 ' . count($lines) . ' 筆。';
        }
    }

    if ($action === 'save_inventory_adjustment') {
        $type = ($_POST['adjust_type'] ?? 'loss') === 'overage' ? 'overage' : 'loss';
        $code = trim((string)($_POST['adjust_code'] ?? ''));
        $qty = max(1, (int)($_POST['adjust_qty'] ?? 1));
        $reason = trim((string)($_POST['adjust_reason'] ?? ''));
        $idx = find_product_key($products, $code);
        if ($idx < 0) {
            $notice = ($type === 'loss' ? '報損' : '報溢') . '失敗：找不到產品條碼或產品編號。';
        } else {
            $beforeQty = (int)($products[$idx]['stock_total'] ?? 0);
            $delta = $type === 'loss' ? -$qty : $qty;
            $products[$idx]['stock_total'] = max(0, $beforeQty + $delta);
            $products[$idx]['updated_at'] = date('c');
            $products[$idx]['updated_by'] = current_operator();
            $row = [
                'id' => uid($type === 'loss' ? 'los_' : 'ovg_'),
                'doc_no' => next_inventory_doc_no($type === 'loss' ? 'LOS' : 'OVG', $inventoryAdjustments),
                'type' => $type,
                'date' => $_POST['adjust_date'] ?: date('Y-m-d'),
                'product_id' => $products[$idx]['id'] ?? '',
                'barcode' => $products[$idx]['barcode'] ?? '',
                'title' => $products[$idx]['title'] ?? '',
                'qty' => $qty,
                'before_qty' => $beforeQty,
                'after_qty' => (int)$products[$idx]['stock_total'],
                'unit_cost' => (float)($products[$idx]['cost'] ?? 0),
                'reason' => $reason,
                'status' => $_POST['adjust_status'] ?? '已登記',
                'operator' => current_operator(),
                'created_at' => date('c'),
            ];
            $inventoryAdjustments[] = $row;
            $stockMovements[] = [
                'id' => uid('mv_'),
                'product_id' => $row['product_id'],
                'barcode' => $row['barcode'],
                'type' => $type === 'loss' ? '報損' : '報溢',
                'qty' => $delta,
                'unit_cost' => $row['unit_cost'],
                'note' => $reason,
                'operator' => current_operator(),
                'created_at' => date('c'),
            ];
            write_data('products', $products);
            write_data('stock_movements', $stockMovements);
            write_data('inventory_adjustments', $inventoryAdjustments);
            $notice = ($type === 'loss' ? '報損單已建立：' : '報溢單已建立：') . $row['doc_no'];
        }
    }

    if ($action === 'save_company_profile') {
        $companyProfile = [
            'company_name' => trim((string)($_POST['company_name'] ?? '')),
            'phone' => trim((string)($_POST['phone'] ?? '')),
            'fax' => trim((string)($_POST['fax'] ?? '')),
            'address' => trim((string)($_POST['address'] ?? '')),
            'contact_name' => trim((string)($_POST['contact_name'] ?? '')),
            'email' => trim((string)($_POST['email'] ?? '')),
            'line_id' => trim((string)($_POST['line_id'] ?? '')),
            'wechat_id' => trim((string)($_POST['wechat_id'] ?? '')),
            'updated_by' => current_operator(),
            'updated_at' => date('c'),
        ];
        write_data('company_profile', [$companyProfile]);
        $notice = '本公司資料已儲存。';
    }

    if ($action === 'save_logistics_company') {
        $id = trim((string)($_POST['logistics_id'] ?? ''));
        $name = trim((string)($_POST['logistics_name'] ?? ''));
        $defaultFee = (float)($_POST['default_fee'] ?? 0);
        if ($name !== '') {
            $updated = false;
            foreach ($logistics as &$row) {
                if ($id !== '' && ($row['id'] ?? '') === $id) {
                    $row['name'] = $name;
                    $row['default_fee'] = $defaultFee;
                    $row['updated_at'] = date('c');
                    $row['updated_by'] = current_operator();
                    $updated = true;
                    break;
                }
            }
            unset($row);
            if ($updated) {
                write_data('logistics', $logistics);
                $notice = '物流公司已更新：' . $name . '，預設運費 ' . money($defaultFee);
            } else {
                $exists = false;
                foreach ($logistics as $row) {
                    if (trim((string)($row['name'] ?? '')) === $name) $exists = true;
                }
                if (!$exists) {
                    $logistics[] = ['id' => uid('logi_'), 'name' => $name, 'default_fee' => $defaultFee, 'created_by' => current_operator(), 'created_at' => date('c')];
                    write_data('logistics', $logistics);
                    $notice = '物流公司已新增：' . $name . '，預設運費 ' . money($defaultFee);
                } else {
                    $notice = '物流公司已存在，若要改金額請在下方清單按更新。';
                }
            }
        }
    }

    if ($action === 'delete_logistics_company') {
        $id = trim((string)($_POST['logistics_id'] ?? ''));
        $logistics = array_values(array_filter($logistics, function($row) use ($id) { return ($row['id'] ?? '') !== $id; }));
        write_data('logistics', $logistics);
        $notice = '物流公司已刪除。';
    }
    if ($action === 'delete_warehouse_items') {
        $ids = $_POST['warehouse_item_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_filter(array_map('trim', $ids)));
        if ($ids) {
            $before = count($warehouses);
            $warehouses = array_values(array_filter($warehouses, function($w) use ($ids) { return !in_array($w['id'] ?? '', $ids, true); }));
            write_data('warehouses', $warehouses);
            $notice = '已刪除 ' . ($before - count($warehouses)) . ' 筆貨倉設定。';
        } else {
            $notice = '請先勾選要刪除的貨倉設定。';
        }
    }

    if ($action === 'delete_warehouse_item') {
        $id = trim($_POST['warehouse_item_id'] ?? '');
        $warehouses = array_values(array_filter($warehouses, function($w) use ($id) { return ($w['id'] ?? '') !== $id; }));
        write_data('warehouses', $warehouses);
        $notice = '已刪除貨倉設定。';
    }

    if ($action === 'update_stock_costs') {
        $costs = $_POST['product_costs'] ?? [];
        if (!is_array($costs)) $costs = [];
        $updated = 0;
        foreach ($products as &$p) {
            $id = $p['id'] ?? '';
            if ($id !== '' && array_key_exists($id, $costs)) {
                $p['cost'] = (float)$costs[$id];
                $p['updated_at'] = date('c');
                $updated++;
            }
        }
        unset($p);
        write_data('products', $products);
        $notice = '已更新庫存管理建檔金額 ' . $updated . ' 筆。';
    }


    if ($action === 'quick_product_images') {
        $id = trim((string)($_POST['product_id'] ?? ''));
        $idx = find_product_key($products, $id);
        if ($idx < 0) {
            $notice = '找不到要補圖的產品。';
        } else {
            $changed = apply_product_uploaded_images($products[$idx], $id, 'quick_main_image', 'quick_extra_images');
            write_data('products', $products);
            $notice = $changed
                ? '產品圖片已補上：' . ($products[$idx]['id'] ?? $id)
                : '沒有讀到可上傳的圖片。請用拍照或相簿選 jpg / png / webp。';
        }
    }

    if ($action === 'save_product') {
        $editingId = trim($_POST['editing_product_id'] ?? '');
        $postedCategoryGroup = trim($_POST['category_group'] ?? '');
        $postedCategoryType = trim($_POST['category_type'] ?? '');
        $postedCategoryBrand = trim($_POST['category_brand'] ?? '');
        $postedCategorySpec = trim($_POST['category_spec'] ?? '');
        if ($postedCategoryGroup === '') $postedCategoryGroup = '組裝硬體';
        $selectedCategoryRule = find_product_category_rule($productCategories, $postedCategoryGroup, $postedCategoryType, $postedCategoryBrand, $postedCategorySpec);
        if ($selectedCategoryRule === null && $postedCategoryType !== '') {
            $selectedCategoryRule = ensure_product_category_rule($productCategories, $postedCategoryGroup, $postedCategoryType, $postedCategoryBrand, $postedCategorySpec);
        }
        $selectedTypeCode = category_type_code($postedCategoryType, $selectedCategoryRule['type_code'] ?? '', $selectedCategoryRule['barcode_prefix'] ?? '');
        $selectedBarcodePrefix = normalize_barcode_prefix($selectedCategoryRule['barcode_prefix'] ?? '');
        if ($editingId === '' && $postedCategoryType === '') {
            $notice = '產品建檔失敗：請選擇或手打分類大綱。';
        } elseif ($editingId === '' && $selectedTypeCode === '') {
            $notice = '產品建檔失敗：這個分類大綱還沒有英文碼。請到產品分類填「分類英文碼」，例如主機 COM。';
        } else {
        $id = $editingId !== '' ? $editingId : trim($_POST['id'] ?? '');
        $existing = $editingId !== '' ? product_by_id($products, $id) : [];
        if ($editingId === '') {
            $serialAssigned = next_type_serial($products, $selectedTypeCode);
            $id = $serialAssigned;
            $existing = product_by_id($products, $id);
            if (!empty($existing)) {
                $id = next_type_serial(array_merge($products, [['original_product_code' => $id]]), $selectedTypeCode);
                $existing = [];
            }
        }
        $main = $existing['image'] ?? '';
        $extra = $existing['extra_images'] ?? [];
        $upMain = upload_image('image', $id, 'uploads/products');
        $upExtra = upload_image('extra_image', $id . '-extra', 'uploads/products');
        $multi = upload_images('photos', $id . '-photo', 'uploads/products');
        if ($upMain) $main = $upMain;
        if ($upExtra) $extra[] = $upExtra;
        foreach ($multi as $img) $extra[] = $img;
        $requestedStock = max(0, (int)($existing['stock_total'] ?? 0));
        $purchaseSource = trim((string)($_POST['purchase_source'] ?? ($existing['purchase_source'] ?? '其他'))) ?: '其他';
        ensure_named_supplier($suppliers, $purchaseSource);
        // Product master costs are recorded in CNY; cost/latest_cost remain TWD for accounting and barcode output.
        $purchaseSourceCurrency = 'CNY';
        $purchaseSourceUnitCost = max(0, (float)($_POST['purchase_source_unit_cost'] ?? ($existing['purchase_source_unit_cost'] ?? 0)));
        $purchaseExchangeRate = max(0.0001, (float)($_POST['purchase_exchange_rate'] ?? ($existing['purchase_exchange_rate'] ?? ($purchaseCostSettings['rmb_fixed_rate'] ?? 5))));
        $productCost = max(0, (float)($_POST['cost'] ?? ($existing['cost'] ?? 0)));
        if ($purchaseSourceUnitCost > 0) {
            $productCost = round($purchaseSourceUnitCost * $purchaseExchangeRate, 4);
        }
        $salePrice = max(0, (float)($_POST['sale_price'] ?? ($existing['sale_price'] ?? 0)));
        $referencePrice = max(0, (float)($existing['reference_price'] ?? 0));
        $referenceSource = trim((string)($existing['reference_source'] ?? ''));
        $referenceUrl = trim((string)($existing['reference_url'] ?? ''));
        $referenceCheckedAt = trim((string)($existing['reference_checked_at'] ?? ''));
        $publishStorefront = !empty($_POST['publish_storefront']);
        $postedTitle = trim($_POST['title'] ?? '');
        $sunfarLabel = $postedTitle . ' ' . $postedCategoryType . ' ' . $postedCategorySpec;
        $sunfarOwnBuild = $referenceSource !== ''
            && preg_match('/(順發|isunfar)/iu', $referenceSource . ' ' . $referenceUrl)
            && (
                preg_match('/(順發.{0,8}(組裝|自組).{0,8}(主機|電腦)|(組裝|自組).{0,8}(主機|電腦))/u', $sunfarLabel)
                || (($postedCategoryBrand === '' || preg_match('/順發/u', $postedCategoryBrand)) && preg_match('/(桌上型電腦|電腦主機|套裝主機)/u', $sunfarLabel))
            );
        if ($sunfarOwnBuild) {
            $publishStorefront = false;
            $notice = '已儲存產品，但依公司上架規則阻擋此類外部自有組裝主機。';
        }
        $productColorCode = trim($_POST['color_code'] ?? ($existing['color_code'] ?? ''));
        $productSizeCode = trim($_POST['size_code'] ?? ($existing['size_code'] ?? ''));
        $serialBase = product_serial_base(array_merge($existing, [
            'id' => $id,
            'original_product_code' => $existing['original_product_code'] ?? ($editingId === '' ? $id : ''),
        ]));
        if ($serialBase === '') $serialBase = $id;
        $productBarcode = build_product_cost_barcode($serialBase, $productCost, $productColorCode);
        if ($editingId === '') $id = $productBarcode !== '' ? $productBarcode : $serialBase;
        $row = [
            'id' => $id,
            'barcode' => $productBarcode,
            'original_product_code' => $serialBase,
            'title' => $postedTitle,
            'product_name' => trim($_POST['product_name'] ?? ($_POST['title'] ?? '')),
            'department' => trim($_POST['department'] ?? ($existing['department'] ?? '電腦部門')),
            'color_module' => trim($_POST['color_module'] ?? '') ?: 'cm_clothes_shared',
            'color' => trim($_POST['color'] ?? ''),
            'color_code' => trim($_POST['color_code'] ?? ''),
            'size' => trim($_POST['size'] ?? ''),
            'size_code' => trim($_POST['size_code'] ?? ''),
            'barcode_cost' => $productCost,
            'purchase_source' => $purchaseSource,
            'purchase_source_currency' => $purchaseSourceCurrency,
            'purchase_source_unit_cost' => $purchaseSourceUnitCost,
            'purchase_exchange_rate' => $purchaseExchangeRate,
            'category_group' => $postedCategoryGroup,
            'product_condition' => (trim($_POST['product_condition'] ?? ($existing['product_condition'] ?? '')) ?: '二手品'),
            'category_type' => $postedCategoryType,
            'category_brand' => $postedCategoryBrand,
            'category_spec' => $postedCategorySpec,
            'spec' => trim($_POST['spec'] ?? ''),
            'warehouse_name' => trim($_POST['warehouse_name'] ?? ''),
            'shelf_code' => normalize_shelf_code($_POST['shelf_code'] ?? ''),
            'warehouse_location' => (normalize_shelf_code($_POST['shelf_code'] ?? '') === '')
                ? ''
                : normalize_warehouse_layer($_POST['warehouse_location'] ?? '', $_POST['shelf_code'] ?? ''),
            'description_source' => trim($_POST['description_source'] ?? ''),
            'description' => trim($_POST['description'] ?? '') !== ''
                ? trim($_POST['description'] ?? '')
                : trim($_POST['description_source'] ?? ''),
            'cost' => $productCost,
            'latest_cost' => (float)($existing['latest_cost'] ?? $productCost),
            'latest_cost_batch_id' => $existing['latest_cost_batch_id'] ?? '',
            'latest_cost_formula' => $existing['latest_cost_formula'] ?? '',
            'latest_cost_updated_at' => $existing['latest_cost_updated_at'] ?? '',
            'latest_cost_updated_by' => $existing['latest_cost_updated_by'] ?? '',
            'cost_version' => (int)($existing['cost_version'] ?? 0),
            'sale_price' => $salePrice,
            'reference_price' => $referencePrice,
            'reference_source' => $referenceSource,
            'reference_url' => $referenceUrl,
            'reference_checked_at' => $referenceCheckedAt,
            'publish_storefront' => $publishStorefront,
            'public_image_approved' => !empty($_POST['public_image_approved']),
            'stock_total' => $requestedStock,
            'stock_reserved' => (int)($existing['stock_reserved'] ?? 0),
            'stock_sold' => (int)($existing['stock_sold'] ?? 0),
            'cloud_auction_reserved' => (int)($existing['cloud_auction_reserved'] ?? 0),
            'cloud_auction_locked' => !empty($existing['cloud_auction_locked']),
            'cloud_inventory_source' => $existing['cloud_inventory_source'] ?? '',
            'cloud_inventory_source_row' => $existing['cloud_inventory_source_row'] ?? 0,
            'cloud_inventory_listed_date' => $existing['cloud_inventory_listed_date'] ?? '',
            'cloud_inventory_remaining' => $existing['cloud_inventory_remaining'] ?? 0,
            'cloud_inventory_shelves' => $existing['cloud_inventory_shelves'] ?? [],
            'cloud_inventory_balance_valid' => $existing['cloud_inventory_balance_valid'] ?? true,
            'cloud_confirmed_sales' => $existing['cloud_confirmed_sales'] ?? [],
            'cloud_pending_shipments' => $existing['cloud_pending_shipments'] ?? [],
            'cloud_active_auctions' => $existing['cloud_active_auctions'] ?? [],
            'cloud_sync_updated_at' => $existing['cloud_sync_updated_at'] ?? '',
            'start_price' => (float)($existing['start_price'] ?? 1),
            'bid_step' => (float)($existing['bid_step'] ?? 10),
            'image' => $main,
            'extra_images' => array_values(array_unique($extra)),
            'status' => $_POST['status'] ?? '可排程',
            'created_at' => $existing['created_at'] ?? date('c'),
            'updated_at' => date('c'),
        ];
        $found = false;
        foreach ($products as &$p) if (($p['id'] ?? '') === $id) { $p = $row; $found = true; break; }
        unset($p);
        if (!$found) $products[] = $row;
        write_data('products', $products);
        if (remember_product_spec($productSpecs, $row['spec'] ?? '')) write_data('product_specs', $productSpecs);
        if (empty($notice)) $notice = '商品已儲存。';
        header('Location: operations.php?edit_product=' . rawurlencode((string)$id) . '&product_saved=1#products');
        exit;
        }
    }

    if ($action === 'save_product_category') {
        $categoryId = trim($_POST['category_id'] ?? '');
        if ($categoryId === '') $categoryId = uid('pc_');
        $previousCategory = null;
        foreach ($productCategories as $existingCategory) {
            if (($existingCategory['id'] ?? '') === $categoryId) {
                $previousCategory = $existingCategory;
                break;
            }
        }
        $barcodePrefix = normalize_barcode_prefix($_POST['category_barcode_prefix'] ?? '');
        $typeCode = category_type_code(trim($_POST['category_type'] ?? ''), $_POST['category_type_code'] ?? '', $barcodePrefix);
        $prefixConflict = false;
        foreach ($productCategories as $existingCategory) {
            if (($existingCategory['id'] ?? '') === $categoryId) continue;
            $existingPrefix = normalize_barcode_prefix($existingCategory['barcode_prefix'] ?? '');
            if ($barcodePrefix !== '' && $existingPrefix === $barcodePrefix && $existingPrefix !== category_type_code($existingCategory['type'] ?? '', $existingCategory['type_code'] ?? '', $existingPrefix)) {
                $prefixConflict = true;
                break;
            }
        }
        if ($previousCategory === null && $typeCode === '') {
            $notice = '產品分類儲存失敗：請填分類英文碼，例如主機 COM、顯示卡 GPU。';
        } elseif ($prefixConflict) {
            $notice = '產品分類儲存失敗：條碼前綴已被其他細分類使用。';
        } else {
        $row = [
            'id' => $categoryId,
            'group' => trim($_POST['category_group'] ?? ''),
            'type' => trim($_POST['category_type'] ?? ''),
            'brand' => trim($_POST['category_brand'] ?? ''),
            'spec' => trim($_POST['category_spec'] ?? ''),
            'type_code' => $typeCode,
            'barcode_prefix' => $barcodePrefix !== '' ? $barcodePrefix : $typeCode,
            'sort' => (int)($_POST['category_sort'] ?? 0),
            'storefront_order' => (int)($_POST['storefront_order'] ?? ($previousCategory['storefront_order'] ?? 0)),
            'note' => trim($_POST['category_note'] ?? ''),
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        $found = false;
        foreach ($productCategories as &$c) {
            if (($c['id'] ?? '') === $categoryId) {
                $row['created_at'] = $c['created_at'] ?? date('c');
                $c = $row;
                $found = true;
                break;
            }
        }
        unset($c);
        if (!$found) $productCategories[] = $row;
        usort($productCategories, function($a, $b) {
            $sa = (int)($a['sort'] ?? 0); $sb = (int)($b['sort'] ?? 0);
            return $sa === $sb ? strnatcasecmp(($a['group'] ?? '') . ($a['type'] ?? '') . ($a['brand'] ?? '') . ($a['spec'] ?? ''), ($b['group'] ?? '') . ($b['type'] ?? '') . ($b['brand'] ?? '') . ($b['spec'] ?? '')) : ($sa <=> $sb);
        });
        write_data('product_categories', $productCategories);
        if ($previousCategory !== null) {
            $oldGroup = trim((string)($previousCategory['group'] ?? ''));
            $oldType = trim((string)($previousCategory['type'] ?? ''));
            $oldBrand = trim((string)($previousCategory['brand'] ?? ''));
            $oldSpec = trim((string)($previousCategory['spec'] ?? ''));
            $productsChanged = false;
            foreach ($products as &$product) {
                $productGroup = trim((string)($product['category_group'] ?? ($product['department'] ?? '')));
                $productType = trim((string)($product['category_type'] ?? ($product['main_category'] ?? '')));
                $productBrand = trim((string)($product['category_brand'] ?? ''));
                $productSpec = trim((string)($product['category_spec'] ?? ''));
                if ($productGroup !== $oldGroup || $productType !== $oldType || $productBrand !== $oldBrand || $productSpec !== $oldSpec) continue;
                $product['category_group'] = $row['group'];
                $product['category_type'] = $row['type'];
                $product['category_brand'] = $row['brand'];
                $product['category_spec'] = $row['spec'];
                if (isset($product['department']) && trim((string)$product['department']) === $oldGroup) $product['department'] = $row['group'];
                if (isset($product['main_category']) && trim((string)$product['main_category']) === $oldType) $product['main_category'] = $row['type'];
                $product['updated_at'] = date('c');
                $productsChanged = true;
            }
            unset($product);
            if ($productsChanged) write_data('products', $products);
        }
        $notice = '產品分類已儲存。';
        }
    }

    if ($action === 'move_product_category_type') {
        $targetGroup = trim((string)($_POST['category_group'] ?? ''));
        $targetType = trim((string)($_POST['category_type'] ?? ''));
        $direction = trim((string)($_POST['direction'] ?? ''));
        $typeMeta = [];
        foreach ($productCategories as $categoryRow) {
            $groupName = trim((string)($categoryRow['group'] ?? ''));
            $typeName = trim((string)($categoryRow['type'] ?? ''));
            if ($groupName !== $targetGroup || $typeName === '') continue;
            if (!isset($typeMeta[$typeName])) {
                $typeMeta[$typeName] = ['order' => PHP_INT_MAX, 'sort' => PHP_INT_MAX];
            }
            $menuOrder = (int)($categoryRow['storefront_order'] ?? 0);
            $leafSort = (int)($categoryRow['sort'] ?? 0);
            if ($menuOrder > 0) $typeMeta[$typeName]['order'] = min($typeMeta[$typeName]['order'], $menuOrder);
            $typeMeta[$typeName]['sort'] = min($typeMeta[$typeName]['sort'], $leafSort);
        }
        uksort($typeMeta, function($a, $b) use ($typeMeta) {
            $orderCompare = $typeMeta[$a]['order'] <=> $typeMeta[$b]['order'];
            if ($orderCompare !== 0) return $orderCompare;
            $sortCompare = $typeMeta[$a]['sort'] <=> $typeMeta[$b]['sort'];
            return $sortCompare !== 0 ? $sortCompare : strnatcasecmp($a, $b);
        });
        $orderedTypes = array_keys($typeMeta);
        $index = array_search($targetType, $orderedTypes, true);
        $swapIndex = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : -1);
        if ($targetGroup !== '' && $index !== false && isset($orderedTypes[$swapIndex])) {
            $temp = $orderedTypes[$index];
            $orderedTypes[$index] = $orderedTypes[$swapIndex];
            $orderedTypes[$swapIndex] = $temp;
            $typeOrders = array_flip($orderedTypes);
            foreach ($productCategories as &$categoryRow) {
                if (trim((string)($categoryRow['group'] ?? '')) !== $targetGroup) continue;
                $typeName = trim((string)($categoryRow['type'] ?? ''));
                if (!isset($typeOrders[$typeName])) continue;
                $categoryRow['storefront_order'] = ((int)$typeOrders[$typeName] + 1) * 10;
                $categoryRow['updated_at'] = date('c');
            }
            unset($categoryRow);
            write_data('product_categories', $productCategories);
            $notice = '商城主類別順序已更新。';
        } else {
            $notice = '商城主類別順序沒有變更。';
        }
    }

    if ($action === 'reorder_product_category_types') {
        $targetGroup = trim((string)($_POST['category_group'] ?? ''));
        $requestedTypes = json_decode((string)($_POST['type_order'] ?? '[]'), true);
        if (!is_array($requestedTypes)) $requestedTypes = [];
        $knownTypes = [];
        foreach ($productCategories as $categoryRow) {
            if (trim((string)($categoryRow['group'] ?? '')) !== $targetGroup) continue;
            $typeName = trim((string)($categoryRow['type'] ?? ''));
            if ($typeName !== '') $knownTypes[$typeName] = true;
        }
        $orderedTypes = [];
        foreach ($requestedTypes as $typeName) {
            $typeName = trim((string)$typeName);
            if ($typeName !== '' && isset($knownTypes[$typeName]) && !in_array($typeName, $orderedTypes, true)) $orderedTypes[] = $typeName;
        }
        foreach (array_keys($knownTypes) as $typeName) {
            if (!in_array($typeName, $orderedTypes, true)) $orderedTypes[] = $typeName;
        }
        if ($targetGroup !== '' && $orderedTypes) {
            $typeOrders = array_flip($orderedTypes);
            foreach ($productCategories as &$categoryRow) {
                if (trim((string)($categoryRow['group'] ?? '')) !== $targetGroup) continue;
                $typeName = trim((string)($categoryRow['type'] ?? ''));
                if (!isset($typeOrders[$typeName])) continue;
                $categoryRow['storefront_order'] = ((int)$typeOrders[$typeName] + 1) * 10;
                $categoryRow['updated_at'] = date('c');
            }
            unset($categoryRow);
            write_data('product_categories', $productCategories);
            $notice = '商城主類別拖曳順序已儲存。';
        } else {
            $notice = '商城主類別拖曳順序儲存失敗。';
        }
    }

    if ($action === 'rename_product_category_bucket') {
        $sourceBucket = json_decode((string)($_POST['source_bucket'] ?? ''), true);
        $oldGroup = is_array($sourceBucket) ? trim((string)($sourceBucket[0] ?? '')) : '';
        $oldType = is_array($sourceBucket) ? trim((string)($sourceBucket[1] ?? '')) : '';
        $newGroup = trim((string)($_POST['target_group'] ?? ''));
        $newType = trim((string)($_POST['target_type'] ?? ''));
        if ($oldGroup === '' || $oldType === '' || $newGroup === '' || $newType === '') {
            $notice = '類別改名失敗：群組與類別名稱不可空白。';
        } else {
            $categoryChanged = false;
            foreach ($productCategories as &$categoryRow) {
                if (trim((string)($categoryRow['group'] ?? '')) !== $oldGroup || trim((string)($categoryRow['type'] ?? '')) !== $oldType) continue;
                $categoryRow['group'] = $newGroup;
                $categoryRow['type'] = $newType;
                $categoryRow['updated_at'] = date('c');
                $categoryChanged = true;
            }
            unset($categoryRow);
            if ($categoryChanged) {
                $dedupedCategories = [];
                $categoryIndexes = [];
                foreach ($productCategories as $categoryRow) {
                    $signature = trim((string)($categoryRow['group'] ?? '')) . '|' . trim((string)($categoryRow['type'] ?? '')) . '|' . trim((string)($categoryRow['brand'] ?? '')) . '|' . trim((string)($categoryRow['spec'] ?? ''));
                    if (!isset($categoryIndexes[$signature])) {
                        $categoryIndexes[$signature] = count($dedupedCategories);
                        $dedupedCategories[] = $categoryRow;
                        continue;
                    }
                    $existingIndex = $categoryIndexes[$signature];
                    foreach (['barcode_prefix', 'note'] as $mergeField) {
                        if (trim((string)($dedupedCategories[$existingIndex][$mergeField] ?? '')) === '' && trim((string)($categoryRow[$mergeField] ?? '')) !== '') {
                            $dedupedCategories[$existingIndex][$mergeField] = $categoryRow[$mergeField];
                        }
                    }
                    $dedupedCategories[$existingIndex]['sort'] = min((int)($dedupedCategories[$existingIndex]['sort'] ?? 0), (int)($categoryRow['sort'] ?? 0));
                }
                $productCategories = array_values($dedupedCategories);
                usort($productCategories, function($a, $b) {
                    $sa = (int)($a['sort'] ?? 0); $sb = (int)($b['sort'] ?? 0);
                    return $sa === $sb ? strnatcasecmp(($a['group'] ?? '') . ($a['type'] ?? '') . ($a['brand'] ?? '') . ($a['spec'] ?? ''), ($b['group'] ?? '') . ($b['type'] ?? '') . ($b['brand'] ?? '') . ($b['spec'] ?? '')) : ($sa <=> $sb);
                });
                write_data('product_categories', $productCategories);
            }
            $productsChanged = false;
            foreach ($products as &$product) {
                $productGroup = trim((string)($product['category_group'] ?? ($product['department'] ?? '')));
                $productType = trim((string)($product['category_type'] ?? ($product['main_category'] ?? '')));
                if ($productGroup !== $oldGroup || $productType !== $oldType) continue;
                $product['category_group'] = $newGroup;
                $product['category_type'] = $newType;
                if (isset($product['department']) && trim((string)$product['department']) === $oldGroup) $product['department'] = $newGroup;
                if (isset($product['main_category']) && trim((string)$product['main_category']) === $oldType) $product['main_category'] = $newType;
                $product['updated_at'] = date('c');
                $productsChanged = true;
            }
            unset($product);
            if ($productsChanged) write_data('products', $products);
            $notice = $categoryChanged ? '類別名稱已更新為「' . $newGroup . ' / ' . $newType . '」，相關細分類與產品已同步。' : '類別改名失敗：找不到原類別。';
        }
    }

    if ($action === 'delete_product_category') {
        $categoryId = trim($_POST['category_id'] ?? '');
        $productCategories = array_values(array_filter($productCategories, function($c) use ($categoryId) { return ($c['id'] ?? '') !== $categoryId; }));
        write_data('product_categories', $productCategories);
        $notice = '產品分類已刪除。';
    }

    if ($action === 'move_product_category_bucket') {
        $categoryId = trim($_POST['category_id'] ?? '');
        $targetBucket = json_decode((string)($_POST['target_bucket'] ?? ''), true);
        $targetGroup = is_array($targetBucket) ? trim((string)($targetBucket[0] ?? '')) : '';
        $targetType = is_array($targetBucket) ? trim((string)($targetBucket[1] ?? '')) : '';
        $sourceCategory = null;
        foreach ($productCategories as $categoryRow) {
            if (($categoryRow['id'] ?? '') === $categoryId) {
                $sourceCategory = $categoryRow;
                break;
            }
        }
        $targetExists = false;
        foreach ($productCategories as $categoryRow) {
            if (trim((string)($categoryRow['group'] ?? '')) === $targetGroup && trim((string)($categoryRow['type'] ?? '')) === $targetType) {
                $targetExists = true;
                break;
            }
        }
        if ($sourceCategory === null || $targetGroup === '' || $targetType === '' || !$targetExists) {
            $notice = '搬移失敗：請選擇有效的目的類別。';
        } else {
            $oldGroup = trim((string)($sourceCategory['group'] ?? ''));
            $oldType = trim((string)($sourceCategory['type'] ?? ''));
            $oldBrand = trim((string)($sourceCategory['brand'] ?? ''));
            $oldSpec = trim((string)($sourceCategory['spec'] ?? ''));
            $duplicateTarget = false;
            foreach ($productCategories as $categoryRow) {
                if (($categoryRow['id'] ?? '') === $categoryId) continue;
                if (trim((string)($categoryRow['group'] ?? '')) === $targetGroup && trim((string)($categoryRow['type'] ?? '')) === $targetType && trim((string)($categoryRow['brand'] ?? '')) === $oldBrand && trim((string)($categoryRow['spec'] ?? '')) === $oldSpec) {
                    $duplicateTarget = true;
                    break;
                }
            }
            if ($duplicateTarget) {
                $productCategories = array_values(array_filter($productCategories, function($categoryRow) use ($categoryId) {
                    return ($categoryRow['id'] ?? '') !== $categoryId;
                }));
            } else {
                foreach ($productCategories as &$categoryRow) {
                    if (($categoryRow['id'] ?? '') !== $categoryId) continue;
                    $categoryRow['group'] = $targetGroup;
                    $categoryRow['type'] = $targetType;
                    $categoryRow['updated_at'] = date('c');
                    break;
                }
                unset($categoryRow);
            }
            usort($productCategories, function($a, $b) {
                $sa = (int)($a['sort'] ?? 0); $sb = (int)($b['sort'] ?? 0);
                return $sa === $sb ? strnatcasecmp(($a['group'] ?? '') . ($a['type'] ?? '') . ($a['brand'] ?? '') . ($a['spec'] ?? ''), ($b['group'] ?? '') . ($b['type'] ?? '') . ($b['brand'] ?? '') . ($b['spec'] ?? '')) : ($sa <=> $sb);
            });
            write_data('product_categories', $productCategories);
            $productsChanged = false;
            foreach ($products as &$product) {
                $productGroup = trim((string)($product['category_group'] ?? ($product['department'] ?? '')));
                $productType = trim((string)($product['category_type'] ?? ($product['main_category'] ?? '')));
                $productBrand = trim((string)($product['category_brand'] ?? ''));
                $productSpec = trim((string)($product['category_spec'] ?? ''));
                if ($productGroup !== $oldGroup || $productType !== $oldType || $productBrand !== $oldBrand || $productSpec !== $oldSpec) continue;
                $product['category_group'] = $targetGroup;
                $product['category_type'] = $targetType;
                if (isset($product['department']) && trim((string)$product['department']) === $oldGroup) $product['department'] = $targetGroup;
                if (isset($product['main_category']) && trim((string)$product['main_category']) === $oldType) $product['main_category'] = $targetType;
                $product['updated_at'] = date('c');
                $productsChanged = true;
            }
            unset($product);
            if ($productsChanged) write_data('products', $products);
            $notice = '細分類已搬移到「' . $targetGroup . ' / ' . $targetType . '」。';
        }
    }

    if ($action === 'bulk_delete_product_categories') {
        $ids = $_POST['category_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
        if (!$ids) {
            $notice = '批次刪除失敗：請先勾選產品分類。';
        } else {
            $before = count($productCategories);
            $productCategories = array_values(array_filter($productCategories, function($c) use ($ids) {
                return !in_array((string)($c['id'] ?? ''), $ids, true);
            }));
            write_data('product_categories', $productCategories);
            $notice = '已刪除 ' . ($before - count($productCategories)) . ' 筆產品分類。';
        }
    }

    if ($action === 'move_product_category') {
        $categoryId = trim($_POST['category_id'] ?? '');
        $direction = trim($_POST['direction'] ?? '');
        usort($productCategories, function($a, $b) {
            $sa = (int)($a['sort'] ?? 0); $sb = (int)($b['sort'] ?? 0);
            return $sa === $sb ? strnatcasecmp(($a['group'] ?? '') . ($a['type'] ?? '') . ($a['brand'] ?? '') . ($a['spec'] ?? ''), ($b['group'] ?? '') . ($b['type'] ?? '') . ($b['brand'] ?? '') . ($b['spec'] ?? '')) : ($sa <=> $sb);
        });
        $index = -1;
        foreach ($productCategories as $i => $c) {
            if (($c['id'] ?? '') === $categoryId) { $index = $i; break; }
        }
        $target = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : -1);
        if ($index >= 0 && isset($productCategories[$target])) {
            $tmp = $productCategories[$index];
            $productCategories[$index] = $productCategories[$target];
            $productCategories[$target] = $tmp;
            foreach ($productCategories as $i => &$c) {
                $c['sort'] = ($i + 1) * 10;
                $c['updated_at'] = date('c');
            }
            unset($c);
            write_data('product_categories', $productCategories);
            $notice = '產品分類排序已更新。';
        } else {
            $notice = '產品分類排序沒有變更。';
        }
    }

    if ($action === 'delete_products_bulk' || $action === 'bulk_delete_products') {
        $ids = $_POST['product_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
        if (!$ids) {
            $notice = '批次刪除失敗：請先勾選產品。';
        } else {
            $blocked = [];
            $deleted = [];
            foreach ($ids as $id) {
                if (product_in_schedules($schedules, $id)) {
                    $blocked[] = $id;
                }
            }
            $products = array_values(array_filter($products, function($p) use ($ids, $blocked, &$deleted) {
                $id = $p['id'] ?? '';
                if (in_array($id, $ids, true) && !in_array($id, $blocked, true)) {
                    $deleted[] = $id;
                    return false;
                }
                return true;
            }));
            write_data('products', $products);
            $parts = [];
            if ($deleted) $parts[] = '已刪除 ' . count($deleted) . ' 筆：' . implode('、', $deleted);
            if ($blocked) $parts[] = '已跳過 ' . count($blocked) . ' 筆已排程產品：' . implode('、', $blocked);
            $notice = $parts ? implode('；', $parts) : '沒有產品被刪除。';
        }
    }

    if ($action === 'delete_product') {
        $id = trim($_POST['id'] ?? '');
        if ($id === '') {
            $notice = '刪除失敗：找不到產品編號。';
        } elseif (product_in_schedules($schedules, $id)) {
            $notice = '刪除失敗：此產品已有排程或歷史場次，請先處理排程，避免庫存與結算斷掉。';
        } else {
            $before = count($products);
            $products = array_values(array_filter($products, function($p) use ($id) { return ($p['id'] ?? '') !== $id; }));
            write_data('products', $products);
            $notice = count($products) < $before ? '產品已刪除：' . $id : '刪除失敗：找不到產品。';
        }
    }

    if ($action === 'save_purchase_cost_rules') {
        $purchaseCostSettings = [
            'id' => 'purchase_cost_settings',
            'rmb_fixed_rate' => max(0.0001, (float)($_POST['rmb_fixed_rate'] ?? 5)),
            'china_warehouse_fee' => max(0, (float)($_POST['china_warehouse_fee'] ?? 30)),
            'taiwan_warehouse_fee' => max(0, (float)($_POST['taiwan_warehouse_fee'] ?? 20)),
            'default_allocation_method' => in_array(($_POST['default_allocation_method'] ?? ''), ['quantity', 'amount'], true) ? $_POST['default_allocation_method'] : 'quantity',
            'updated_by' => current_operator(),
            'updated_at' => date('c'),
        ];
        $purchaseCostRules = [$purchaseCostSettings];
        write_data('purchase_cost_rules', $purchaseCostRules);
        $notice = '進貨成本規則已儲存。';
    }

    if ($action === 'stock_in') {
        $stockItems = $_POST['stock_items'] ?? [];
        if (!is_array($stockItems) || !$stockItems) {
            $stockItems = [[
                'product_key' => $_POST['stock_product_id'] ?? ($_POST['stock_key'] ?? ''),
                'qty' => $_POST['stock_qty'] ?? 0,
                'unit_cost' => $_POST['stock_unit_cost'] ?? '',
                'weight_kg' => $_POST['stock_weight_kg'] ?? 0,
                'warehouse_name' => $_POST['stock_warehouse_name'] ?? '',
                'shelf_code' => $_POST['stock_shelf_code'] ?? '',
                'location' => $_POST['stock_location'] ?? '',
                'note' => $_POST['stock_note'] ?? '',
            ]];
        }

        $supplierId = trim($_POST['stock_supplier_id'] ?? '');
        $supplierName = trim($_POST['stock_supplier_name'] ?? '');
        $stockDocNo = trim((string)($_POST['stock_doc_no'] ?? ''));
        if ($stockDocNo === '') $stockDocNo = next_inventory_doc_no('JH', $stockMovements);
        $stockDocDate = trim((string)($_POST['stock_doc_date'] ?? date('Y-m-d')));
        $stockHandler = trim((string)($_POST['stock_handler'] ?? current_operator()));
        $stockDepartment = trim((string)($_POST['stock_department'] ?? '電腦部門'));
        $stockPaymentStatus = trim((string)($_POST['stock_payment_status'] ?? '未付款'));
        $stockInvoiceNo = trim((string)($_POST['stock_invoice_no'] ?? ''));
        $stockSource = trim((string)($_POST['stock_source'] ?? '其他'));
        $stockCurrency = strtoupper(trim((string)($_POST['stock_currency'] ?? 'TWD')));
        if (!in_array($stockCurrency, ['TWD', 'CNY'], true)) $stockCurrency = 'TWD';
        $defaultRate = $stockCurrency === 'CNY' ? (float)($purchaseCostSettings['rmb_fixed_rate'] ?? 5) : 1;
        $stockExchangeRate = max(0.0001, (float)($_POST['stock_exchange_rate'] ?? $defaultRate));
        $stockAllocationMethod = in_array(($_POST['stock_allocation_method'] ?? ''), ['quantity', 'amount'], true)
            ? $_POST['stock_allocation_method']
            : ($purchaseCostSettings['default_allocation_method'] ?? 'quantity');
        $stockDirectCost = !empty($_POST['stock_direct_cost']);
        if ($stockDirectCost) {
            $stockCurrency = 'TWD';
            $stockExchangeRate = 1;
            if ($stockSource === '' || $stockSource === '其他') $stockSource = '舊系統現貨';
        }
        $stockDiscount = $stockDirectCost ? 0 : max(0, (float)($_POST['stock_discount'] ?? 0));
        $stockTax = $stockDirectCost ? 0 : max(0, (float)($_POST['stock_tax'] ?? 0));
        $stockCustomsFee = $stockDirectCost ? 0 : max(0, (float)($_POST['stock_customs_fee'] ?? 0));
        if ($stockSource === '豪鴻') $stockCustomsFee = 0;
        $stockChinaFreight = $stockDirectCost ? 0 : max(0, (float)($_POST['stock_china_freight'] ?? 0));
        $stockTaiwanShipping = $stockDirectCost ? 0 : max(0, (float)($_POST['stock_taiwan_shipping'] ?? 0));
        $stockShippingFee = $stockDirectCost ? 0 : max(0, (float)($_POST['stock_shipping_fee'] ?? 0));
        $stockOtherFee = $stockDirectCost ? 0 : max(0, (float)($_POST['stock_other_fee'] ?? 0));
        $stockCustomsPackageCount = $stockDirectCost ? 0 : max(0, (int)($_POST['stock_customs_package_count'] ?? 0));
        $stockDocNote = trim((string)($_POST['stock_doc_note'] ?? ''));
        foreach ($suppliers as $sp) {
            if (($sp['id'] ?? '') === $supplierId) {
                $supplierName = $sp['name'] ?? $supplierName;
                break;
            }
        }

        $batchId = uid('pbat_');
        $saved = 0;
        $skipped = 0;
        $savedNames = [];
        $preparedLines = [];
        foreach ($stockItems as $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }
            $key = trim($item['product_key'] ?? '');
            $qty = max(0, (int)($item['qty'] ?? 0));
            $idx = find_product_key($products, $key);
            if ($idx < 0 || $qty <= 0) {
                $skipped++;
                continue;
            }

            $warehouse = trim($item['warehouse_name'] ?? '');
            $shelf = normalize_shelf_code($item['shelf_code'] ?? '');
            $location = $shelf === '' ? '' : normalize_warehouse_layer($item['location'] ?? '', $item['shelf_code'] ?? '');
            $sourceUnitCost = max(0, number_value($item['unit_cost'] ?? ''));
            $baseCostTwd = round($qty * $sourceUnitCost * $stockExchangeRate, 4);
            $weightKg = max(0, number_value($item['weight_kg'] ?? 0));
            $preparedLines[] = [
                'product_index' => $idx,
                'product_key' => $key,
                'qty' => $qty,
                'source_unit_cost' => $sourceUnitCost,
                'base_cost_twd' => $baseCostTwd,
                'weight_kg' => $weightKg,
                'warehouse_name' => $warehouse,
                'shelf_code' => $shelf,
                'location' => $location,
                'note' => trim((string)($item['note'] ?? '')),
            ];
        }

        $shelfCatalogChanged = false;
        foreach ($preparedLines as $line) {
            if (ensure_warehouse_shelf($warehouses, $stockDepartment, $line['warehouse_name'] ?? '', $line['shelf_code'] ?? '')) {
                $shelfCatalogChanged = true;
            }
        }
        if ($shelfCatalogChanged) write_data('warehouses', $warehouses);

        $totalQty = 0;
        $totalWeightKg = 0.0;
        foreach ($preparedLines as $line) {
            $totalQty += (int)($line['qty'] ?? 0);
            if (!purchase_is_china_warehouse($line['warehouse_name'] ?? '')) {
                $totalWeightKg += (float)($line['weight_kg'] ?? 0);
            }
        }
        $costBasisQty = $stockCustomsPackageCount > 0 ? $stockCustomsPackageCount : max(1, $totalQty);
        $useLingzanzan = !$stockDirectCost && in_array($stockSource, ['拼多多', '豪鴻'], true);

        $allocDiscount = $useLingzanzan ? 0 : $stockDiscount;
        $allocTax = $useLingzanzan ? 0 : $stockTax;
        $allocCustoms = $useLingzanzan ? 0 : $stockCustomsFee;
        $allocChina = $useLingzanzan ? 0 : $stockChinaFreight;
        $allocTaiwan = 0;
        $allocBatch = 0;
        $allocOther = $useLingzanzan ? 0 : $stockOtherFee;
        if (!$useLingzanzan) {
            $allocTaiwan = $stockTaiwanShipping;
            $allocBatch = $stockShippingFee;
        }

        $discountAllocations = purchase_allocate_amount($allocDiscount, $preparedLines, $stockAllocationMethod);
        $taxAllocations = purchase_allocate_amount($allocTax, $preparedLines, $stockAllocationMethod);
        $customsAllocations = purchase_allocate_amount($allocCustoms, $preparedLines, $stockAllocationMethod);
        $chinaFreightAllocations = purchase_allocate_amount($allocChina, $preparedLines, $stockAllocationMethod);
        $otherFeeAllocations = purchase_allocate_amount($allocOther, $preparedLines, $stockAllocationMethod);
        $taiwanShippingAllocations = array_fill(0, count($preparedLines), 0.0);
        $batchFreightAllocations = array_fill(0, count($preparedLines), 0.0);

        if ($stockSource === '拼多多' && !$stockDirectCost) {
            $taiwanPerUnit = $costBasisQty > 0 ? round($stockTaiwanShipping / $costBasisQty, 4) : 0;
            foreach ($preparedLines as $lineIndex => $line) {
                if (purchase_is_china_warehouse($line['warehouse_name'] ?? '')) continue;
                $taiwanShippingAllocations[$lineIndex] = round($taiwanPerUnit * (int)($line['qty'] ?? 0), 4);
            }
        } elseif ($stockSource === '豪鴻' && !$stockDirectCost) {
            if ($stockShippingFee > 0 && $totalWeightKg > 0) {
                $batchFreightAllocations = purchase_allocate_by_weight($stockShippingFee, $preparedLines);
            } elseif ($stockShippingFee <= 0 && $totalWeightKg > 0) {
                foreach ($preparedLines as $lineIndex => $line) {
                    if (purchase_is_china_warehouse($line['warehouse_name'] ?? '')) continue;
                    $batchFreightAllocations[$lineIndex] = round((float)($line['weight_kg'] ?? 0) * 8 * $stockExchangeRate, 4);
                }
            } else {
                $batchPerUnit = $costBasisQty > 0 ? round($stockShippingFee / $costBasisQty, 4) : 0;
                foreach ($preparedLines as $lineIndex => $line) {
                    if (purchase_is_china_warehouse($line['warehouse_name'] ?? '')) continue;
                    $batchFreightAllocations[$lineIndex] = round($batchPerUnit * (int)($line['qty'] ?? 0), 4);
                }
            }
        } else {
            $taiwanShippingAllocations = purchase_allocate_amount($allocTaiwan, $preparedLines, $stockAllocationMethod);
            $batchFreightAllocations = purchase_allocate_amount($allocBatch, $preparedLines, $stockAllocationMethod);
        }

        $formula = lingzanzan_stock_in_formula($stockSource, $stockDirectCost);
        $batchLines = [];
        foreach ($preparedLines as $lineIndex => $line) {
            $idx = $line['product_index'];
            $key = $line['product_key'];
            $qty = $line['qty'];
            $warehouse = $line['warehouse_name'];
            $shelf = $line['shelf_code'];
            $location = $line['location'];
            $warehouseFeeUnit = $stockDirectCost ? 0 : purchase_warehouse_fee_unit($warehouse, $purchaseCostSettings);
            $warehouseFeeTotal = round($warehouseFeeUnit * $qty, 4);
            $oldCost = (float)($products[$idx]['cost'] ?? 0);
            $landedTotal = max(0, round(
                $line['base_cost_twd']
                - ($discountAllocations[$lineIndex] ?? 0)
                + ($taxAllocations[$lineIndex] ?? 0)
                + ($customsAllocations[$lineIndex] ?? 0)
                + ($chinaFreightAllocations[$lineIndex] ?? 0)
                + ($taiwanShippingAllocations[$lineIndex] ?? 0)
                + ($batchFreightAllocations[$lineIndex] ?? 0)
                + ($otherFeeAllocations[$lineIndex] ?? 0)
                + $warehouseFeeTotal,
                4
            ));
            $landedUnitCost = $qty > 0 ? round($landedTotal / $qty, 4) : 0;

            $products[$idx]['stock_total'] = (int)($products[$idx]['stock_total'] ?? 0) + $qty;
            if ($warehouse !== '') $products[$idx]['warehouse_name'] = $warehouse;
            if ($shelf !== '') $products[$idx]['shelf_code'] = $shelf;
            if ($location !== '') $products[$idx]['warehouse_location'] = $location;
            $products[$idx]['purchase_source'] = $stockSource;
            $products[$idx]['purchase_source_currency'] = $stockCurrency;
            $products[$idx]['purchase_source_unit_cost'] = $line['source_unit_cost'];
            $products[$idx]['purchase_exchange_rate'] = $stockExchangeRate;
            $products[$idx]['purchase_weight_kg'] = $line['weight_kg'] ?? 0;
            $products[$idx]['purchase_customs_package_count'] = $costBasisQty;
            $products[$idx]['cost'] = $landedUnitCost;
            $products[$idx]['barcode_cost'] = $landedUnitCost;
            $products[$idx]['latest_cost'] = $landedUnitCost;
            $products[$idx]['latest_cost_batch_id'] = $batchId;
            $products[$idx]['latest_cost_formula'] = $formula;
            $products[$idx]['latest_cost_updated_at'] = date('c');
            $products[$idx]['latest_cost_updated_by'] = current_operator();
            $products[$idx]['cost_version'] = (int)($products[$idx]['cost_version'] ?? 0) + 1;
            $products[$idx]['last_stock_in_qty'] = $qty;
            $products[$idx]['last_stock_in_at'] = date('c');
            $latestLabelBarcode = latest_cost_barcode($products[$idx]);

            $lineRecord = [
                'id' => uid('pbl_'),
                'batch_id' => $batchId,
                'product_id' => $products[$idx]['id'] ?? $key,
                'stable_barcode' => $products[$idx]['barcode'] ?? '',
                'latest_label_barcode' => $latestLabelBarcode,
                'qty' => $qty,
                'source_unit_cost' => $line['source_unit_cost'],
                'source_currency' => $stockCurrency,
                'exchange_rate' => $stockExchangeRate,
                'base_cost_twd' => $line['base_cost_twd'],
                'weight_kg' => $line['weight_kg'] ?? 0,
                'customs_package_count' => $costBasisQty,
                'allocated_discount' => $discountAllocations[$lineIndex] ?? 0,
                'allocated_tax' => $taxAllocations[$lineIndex] ?? 0,
                'allocated_customs' => $customsAllocations[$lineIndex] ?? 0,
                'allocated_china_freight' => $chinaFreightAllocations[$lineIndex] ?? 0,
                'allocated_taiwan_shipping' => $taiwanShippingAllocations[$lineIndex] ?? 0,
                'allocated_batch_freight' => $batchFreightAllocations[$lineIndex] ?? 0,
                'allocated_other_fee' => $otherFeeAllocations[$lineIndex] ?? 0,
                'warehouse_fee_unit' => $warehouseFeeUnit,
                'warehouse_fee_total' => $warehouseFeeTotal,
                'old_unit_cost' => $oldCost,
                'landed_total' => $landedTotal,
                'landed_unit_cost' => $landedUnitCost,
                'warehouse_name' => $warehouse !== '' ? $warehouse : ($products[$idx]['warehouse_name'] ?? ''),
                'shelf_code' => $shelf !== '' ? $shelf : ($products[$idx]['shelf_code'] ?? ''),
                'warehouse_location' => $location !== '' ? $location : ($products[$idx]['warehouse_location'] ?? ''),
                'formula' => $formula,
                'note' => $line['note'],
            ];
            $batchLines[] = $lineRecord;

            $stockMovements[] = [
                'id' => uid('stk_'),
                'batch_id' => $batchId,
                'batch_line_id' => $lineRecord['id'],
                'type' => '進貨入庫單',
                'document_no' => $stockDocNo,
                'source_doc_no' => $stockDocNo,
                'source_doc_type' => '進貨入庫單',
                'date' => $stockDocDate,
                'document_date' => $stockDocDate,
                'product_id' => $products[$idx]['id'] ?? $key,
                'barcode' => $products[$idx]['barcode'] ?? '',
                'product_title' => $products[$idx]['title'] ?? '',
                'color' => $products[$idx]['color'] ?? '',
                'color_code' => $products[$idx]['color_code'] ?? '',
                'size' => $products[$idx]['size'] ?? '',
                'size_code' => $products[$idx]['size_code'] ?? '',
                'spec' => $products[$idx]['spec'] ?? '',
                'qty' => $qty,
                'source_unit_cost' => $line['source_unit_cost'],
                'source_currency' => $stockCurrency,
                'exchange_rate' => $stockExchangeRate,
                'base_cost_twd' => $line['base_cost_twd'],
                'weight_kg' => $line['weight_kg'] ?? 0,
                'customs_package_count' => $costBasisQty,
                'unit_cost' => $landedUnitCost,
                'amount' => $landedTotal,
                'total_amount' => $landedTotal,
                'line_subtotal' => $landedTotal,
                'latest_label_barcode' => $latestLabelBarcode,
                'allocated_discount' => $lineRecord['allocated_discount'],
                'allocated_tax' => $lineRecord['allocated_tax'],
                'allocated_customs' => $lineRecord['allocated_customs'],
                'allocated_china_freight' => $lineRecord['allocated_china_freight'],
                'allocated_taiwan_shipping' => $lineRecord['allocated_taiwan_shipping'],
                'allocated_batch_freight' => $lineRecord['allocated_batch_freight'],
                'allocated_other_fee' => $lineRecord['allocated_other_fee'],
                'warehouse_fee_unit' => $warehouseFeeUnit,
                'warehouse_fee_total' => $warehouseFeeTotal,
                'supplier_id' => $supplierId,
                'supplier_name' => $supplierName,
                'party_name' => $supplierName,
                'handler' => $stockHandler,
                'department' => $stockDepartment,
                'payment_status' => $stockPaymentStatus,
                'invoice_no' => $stockInvoiceNo,
                'document_discount' => $stockDiscount,
                'document_tax' => $stockTax,
                'customs_fee' => $stockCustomsFee,
                'china_freight' => $stockChinaFreight,
                'taiwan_shipping' => $stockTaiwanShipping,
                'shipping_fee' => $stockShippingFee,
                'other_fee' => $stockOtherFee,
                'customs_package_count' => $costBasisQty,
                'purchase_source' => $stockSource,
                'allocation_method' => $stockAllocationMethod,
                'cost_formula' => $formula,
                'document_note' => $stockDocNote,
                'warehouse_name' => $warehouse !== '' ? $warehouse : ($products[$idx]['warehouse_name'] ?? ''),
                'shelf_code' => $shelf !== '' ? $shelf : ($products[$idx]['shelf_code'] ?? ''),
                'warehouse_location' => $location !== '' ? $location : ($products[$idx]['warehouse_location'] ?? ''),
                'note' => $line['note'],
                'operator' => current_operator(),
                'created_at' => date('c'),
            ];
            $purchaseCostAudits[] = [
                'id' => uid('pca_'),
                'batch_id' => $batchId,
                'batch_line_id' => $lineRecord['id'],
                'document_no' => $stockDocNo,
                'product_id' => $products[$idx]['id'] ?? $key,
                'stable_barcode' => $products[$idx]['barcode'] ?? '',
                'latest_label_barcode' => $latestLabelBarcode,
                'old_cost' => $oldCost,
                'new_cost' => $landedUnitCost,
                'currency' => $stockCurrency,
                'exchange_rate' => $stockExchangeRate,
                'formula' => $formula,
                'allocation_method' => $stockAllocationMethod,
                'allocation_result' => $lineRecord,
                'operator' => current_operator(),
                'created_at' => date('c'),
            ];
            $saved++;
            $savedNames[] = ($products[$idx]['id'] ?? $key) . ' +' . $qty;
        }

        if ($saved > 0) {
            $purchaseBatches[] = [
                'id' => $batchId,
                'document_no' => $stockDocNo,
                'document_date' => $stockDocDate,
                'supplier_id' => $supplierId,
                'supplier_name' => $supplierName,
                'purchase_source' => $stockSource,
                'currency' => $stockCurrency,
                'exchange_rate' => $stockExchangeRate,
                'allocation_method' => $stockAllocationMethod,
                'discount' => $stockDiscount,
                'tax' => $stockTax,
                'customs_fee' => $stockCustomsFee,
                'china_freight' => $stockChinaFreight,
                'taiwan_shipping' => $stockTaiwanShipping,
                'batch_freight' => $stockShippingFee,
                'other_fee' => $stockOtherFee,
                'customs_package_count' => $costBasisQty,
                'cost_formula' => $formula,
                'payment_status' => $stockPaymentStatus,
                'invoice_no' => $stockInvoiceNo,
                'department' => $stockDepartment,
                'handler' => $stockHandler,
                'note' => $stockDocNote,
                'status' => '已確認入庫',
                'lines' => $batchLines,
                'operator' => current_operator(),
                'created_at' => date('c'),
            ];
            $photoNote = '';
            $firstIdx = (int)($preparedLines[0]['product_index'] ?? -1);
            if ($firstIdx >= 0) {
                $photoPrefix = (string)($products[$firstIdx]['id'] ?? ($preparedLines[0]['product_key'] ?? 'stock'));
                if (apply_product_uploaded_images($products[$firstIdx], $photoPrefix, 'stock_main_image', 'stock_extra_images')) {
                    $photoNote = '，並已補上產品照片';
                }
            }
            write_data('products', $products);
            write_data('stock_movements', $stockMovements);
            write_data('purchase_batches', $purchaseBatches);
            write_data('purchase_cost_audits', $purchaseCostAudits);
            $notice = '已完成進貨入庫 ' . $saved . ' 筆明細：' . implode('、', array_slice($savedNames, 0, 8));
            if (count($savedNames) > 8) $notice .= '...';
            $notice .= $photoNote;
            if ($skipped > 0) $notice .= '；略過 ' . $skipped . ' 筆未選產品或數量不正確。';
        } else {
            $notice = '進貨失敗：請先掃描/搜尋產品並輸入正確數量。';
        }
    }

    if ($action === 'purchase_return') {
        $returnItems = $_POST['purchase_return_items'] ?? [];
        if (!is_array($returnItems) || !$returnItems) {
            $returnItems = [[
                'product_key' => $_POST['purchase_return_product_id'] ?? '',
                'qty' => $_POST['purchase_return_qty'] ?? 0,
                'unit_cost' => $_POST['purchase_return_unit_cost'] ?? '',
                'note' => $_POST['purchase_return_note'] ?? '',
            ]];
        }
        $supplierName = trim((string)($_POST['purchase_return_supplier_name'] ?? ''));
        $docNo = trim((string)($_POST['purchase_return_doc_no'] ?? ''));
        if ($docNo === '' || !ops_doc_no_is_valid($docNo, 'back_f')) {
            $docNo = ops_next_doc_no('back_f', array_merge(ops_collect_doc_nos($stockMovements), ops_collect_doc_nos($returns)));
        }
        $docDate = trim((string)($_POST['purchase_return_doc_date'] ?? date('Y-m-d')));
        $handler = trim((string)($_POST['purchase_return_handler'] ?? current_operator()));
        $reason = trim((string)($_POST['purchase_return_reason'] ?? ''));
        $saved = 0;
        $skipped = 0;
        foreach ($returnItems as $item) {
            if (!is_array($item)) { $skipped++; continue; }
            $key = trim((string)($item['product_key'] ?? ''));
            $qty = max(0, (int)($item['qty'] ?? 0));
            $idx = find_product_key($products, $key);
            if ($idx < 0 || $qty <= 0) { $skipped++; continue; }
            $available = stock_available($products[$idx]);
            $returnQty = min($qty, max(0, (int)($products[$idx]['stock_total'] ?? 0)));
            if ($returnQty <= 0) { $skipped++; continue; }
            $unitCost = number_value($item['unit_cost'] ?? '');
            if ($unitCost <= 0) $unitCost = (float)($products[$idx]['cost'] ?? 0);
            $products[$idx]['stock_total'] = max(0, (int)($products[$idx]['stock_total'] ?? 0) - $returnQty);
            $products[$idx]['updated_at'] = date('c');
            $lineSubtotal = $returnQty * $unitCost;
            $stockMovements[] = [
                'id' => uid('prt_'),
                'type' => '進貨退貨單',
                'document_no' => $docNo,
                'source_doc_no' => $docNo,
                'source_doc_type' => '進貨退貨單',
                'date' => $docDate,
                'document_date' => $docDate,
                'product_id' => $products[$idx]['id'] ?? $key,
                'barcode' => $products[$idx]['barcode'] ?? '',
                'product_title' => $products[$idx]['title'] ?? '',
                'color' => $products[$idx]['color'] ?? '',
                'size' => $products[$idx]['size'] ?? '',
                'spec' => $products[$idx]['spec'] ?? '',
                'qty' => -1 * $returnQty,
                'unit_cost' => $unitCost,
                'amount' => $lineSubtotal,
                'total_amount' => $lineSubtotal,
                'supplier_name' => $supplierName,
                'party_name' => $supplierName,
                'handler' => $handler,
                'reason' => $reason,
                'summary' => '進貨退回給 ' . $supplierName,
                'note' => trim((string)($item['note'] ?? '')),
                'source' => '進貨退貨單',
                'operator' => current_operator(),
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];
            $saved++;
        }
        if ($saved > 0) {
            write_data('products', $products);
            write_data('stock_movements', $stockMovements);
            $notice = '進貨退回單已建立：' . $docNo . '，共 ' . $saved . ' 筆明細。';
            if ($skipped > 0) $notice .= ' 略過 ' . $skipped . ' 筆資料不完整或庫存不足明細。';
        } else {
            $notice = '進貨退回建立失敗：請確認產品、數量與庫存。';
        }
    }

    if ($action === 'sync_default_product_sheet') {
        $csv = fetch_csv_text('https://docs.google.com/spreadsheets/d/1xGtKBpa3glooWMSb4QgIWDdHz0Nr5m54/export?format=csv&gid=657180316');
        $rows = parse_csv_rows($csv);
        if (!$rows) {
            $notice = '同步失敗：讀不到指定 Google 雲端表。請確認該表已開放「知道連結者可檢視」，或已發布成 CSV。';
        } else {
            [$products, $count] = import_product_rows($rows, $products);
            write_data('products', $products);
            $notice = '已從指定 Google 雲端表同步 / 更新產品庫存資料 ' . $count . ' 筆。';
        }
    }

    if ($action === 'import_products_csv') {
        $csv = '';
        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $csv = file_get_contents($_FILES['csv_file']['tmp_name']) ?: '';
        } elseif (!empty($_POST['sheet_csv_url'])) {
            $csv = fetch_csv_text($_POST['sheet_csv_url']);
        }
        $rows = parse_csv_rows($csv);
        if (!$rows) {
            $notice = '匯入失敗：讀不到表格資料。請確認 Google 表已開放檢視，或改上傳 CSV 檔。';
        } else {
            [$products, $count] = import_product_rows($rows, $products);
            write_data('products', $products);
            $notice = '已匯入 / 更新產品庫存資料 ' . $count . ' 筆。';
        }
    }

    if ($action === 'create_schedule') {
        $productKey = trim($_POST['product_key'] ?? ($_POST['product_id'] ?? ''));
        $productKey = trim(explode('/', $productKey)[0]);
        $idx = find_product_key($products, $productKey);
        $product = $idx >= 0 ? $products[$idx] : product_by_id($products, $productKey);
        $productId = $product['id'] ?? $productKey;
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        $image = trim($_POST['schedule_image'] ?? '') ?: ($product['image'] ?? '');
        $upload = upload_image('schedule_image_upload', $productId . '-schedule', 'uploads/schedules');
        if ($upload) $image = $upload;

        $publishDate = trim($_POST['publish_date'] ?? '');
        $publishTime = trim($_POST['publish_time'] ?? '20:00');
        $closeDate = trim($_POST['close_date'] ?? $publishDate);
        $closeTime = trim($_POST['close_time'] ?? '23:59');

        $jobs = [];
        $jobDates = $_POST['job_date'] ?? [];
        $jobPublishTimes = $_POST['job_publish_time'] ?? [];
        $jobCloseDates = $_POST['job_close_date'] ?? [];
        $jobCloseTimes = $_POST['job_close_time'] ?? [];
        $jobQtys = $_POST['job_quantity'] ?? [];
        if (is_array($jobDates)) {
            foreach ($jobDates as $i => $jobDate) {
                $jobDate = trim((string)$jobDate);
                if ($jobDate === '') continue;
                $jobs[] = [
                    'publish_date' => $jobDate,
                    'publish_time' => trim((string)($jobPublishTimes[$i] ?? $publishTime ?: '20:00')),
                    'close_date' => trim((string)($jobCloseDates[$i] ?? $jobDate)) ?: $jobDate,
                    'close_time' => trim((string)($jobCloseTimes[$i] ?? $closeTime ?: '23:59')),
                    'quantity' => max(1, (int)($jobQtys[$i] ?? $qty)),
                ];
            }
        }

        if (!$jobs) {
            $days = array_filter(array_map('trim', preg_split('/\R/', $_POST['days'] ?? '')));
            if (!$days && $publishDate !== '') $days = [$publishDate];
            foreach ($days as $day) {
                $jobs[] = [
                    'publish_date' => $day,
                    'publish_time' => $publishTime ?: '20:00',
                    'close_date' => $closeDate ?: $day,
                    'close_time' => $closeTime ?: '23:59',
                    'quantity' => $qty,
                ];
            }
        }

        $reserveNeed = array_sum(array_map(function($job) { return max(1, (int)($job['quantity'] ?? 1)); }, $jobs));
        $availableNow = !empty($product['id']) ? stock_available($product) : 0;
        if (!$jobs || empty($product['id'])) {
            $notice = '排程建立失敗，請選擇產品並至少新增一筆工作排程。';
        } elseif (!empty($product['cloud_auction_locked'])) {
            $notice = '排程建立失敗：此產品正在外部競標中，雲端庫存已鎖倉，請等待得標結果同步後再上架。';
        } elseif ($reserveNeed > $availableNow) {
            $notice = '排程建立失敗，庫存不足。可用為 ' . max(0, $availableNow) . '，這次需要預約 ' . $reserveNeed . '，請先入庫或降低排程數量。';
        } else {
            foreach ($jobs as $job) {
                $day = $job['publish_date'];
                $thisCloseDate = $job['close_date'] ?: $day;
                $jobQty = max(1, (int)($job['quantity'] ?? 1));
                $schedules[] = [
                    'id' => uid('sch_'),
                    'product_id' => $productId,
                    'product_barcode' => $product['barcode'] ?? '',
                    'product_serial' => trim($_POST['product_serial'] ?? ''),
                    'warranty_serial' => trim($_POST['product_serial'] ?? ''),
                    'product_title' => $product['title'] ?? '',
                    'product_color' => $product['color'] ?? '',
                    'product_size' => $product['size'] ?? '',
                    'product_spec' => $product['spec'] ?? '',
                    'product_description' => $product['description'] ?? '',
                    'warehouse_name' => $product['warehouse_name'] ?? '',
                    'shelf_code' => $product['shelf_code'] ?? '',
                    'warehouse_location' => $product['warehouse_location'] ?? '',
                    'publish_mode' => $_POST['publish_mode'] ?? 'scheduled',
                    'publish_at' => $day . ' ' . ($job['publish_time'] ?: '20:00'),
                    'scheduled_publish_at' => $day . ' ' . ($job['publish_time'] ?: '20:00'),
                    'actual_publish_at' => '',
                    'close_at' => $thisCloseDate . ' ' . ($job['close_time'] ?: '23:59'),
                    'close_remind_at' => $thisCloseDate . ' ' . ($job['close_time'] ?: '23:59'),
                    'quantity' => $jobQty,
                    'status' => '未上架',
                    'publish_status' => '未上架',
                    'order_status' => '待記單',
                    'schedule_image' => $image,
                    'schedule_image_note' => trim($_POST['schedule_image_note'] ?? ''),
                    'post_url' => '',
                    'post_set_id' => (string)(post_reply_find_set($postReplySets, trim((string)($_POST['post_set_id'] ?? '')))['id'] ?? ''),
                    'order_token' => bin2hex(random_bytes(16)),
                    'show_on_buyer_board' => isset($_POST['show_on_buyer_board']) ? '1' : '0',
                    'current_bid' => (float)($product['start_price'] ?? 0),
                    'bid_updated_at' => '',
                    'last_reminder_at' => '',
                    'next_reminder_at' => date('Y-m-d H:i', strtotime($day . ' ' . $publishTime) + 3600),
                    'reminder_status' => '待確認發布',
                    'reminder_draft' => '',
                    'winner' => '',
                    'winner_facebook' => '',
                    'winner_phone' => '',
                    'winner_address' => '',
                    'winning_price' => 0,
                    'paid_amount' => 0,
                    'product_cost' => (float)($product['cost'] ?? 0),
                    'fee_type' => 'amount',
                    'fee_value' => 0,
                    'tax_included' => '0',
                    'shipping_fee' => 0,
                    'other_fee' => 0,
                    'payment_status' => '未付款',
                    'shipping_status' => '未出貨',
                    'payment_date' => '',
                    'shipping_date' => '',
                    'tracking_no' => '',
                    'invoice_no' => '',
                    'settlement_note' => '',
                    'created_by' => current_operator(),
                    'updated_by' => current_operator(),
                    'created_at' => date('c'),
                ];
            }
            foreach ($products as &$p) if (($p['id'] ?? '') === $productId) $p['stock_reserved'] = (int)($p['stock_reserved'] ?? 0) + $reserveNeed;
            unset($p);
            write_data('products', $products);
            write_data('schedules', $schedules);
            $notice = '排程已建立，並已預約庫存。';
        }
    }

    if ($action === 'save_publish_status') {
        foreach ($schedules as &$s) {
            if (($s['id'] ?? '') !== ($_POST['schedule_id'] ?? '')) continue;
            $s['publish_status'] = trim($_POST['publish_status'] ?? ($s['publish_status'] ?? '未上架'));
            $s['status'] = $s['publish_status'];
            $s['actual_publish_at'] = trim($_POST['actual_publish_at'] ?? ($s['actual_publish_at'] ?? ''));
            $s['post_url'] = safe_http_url($_POST['post_url'] ?? ($s['post_url'] ?? ''));
            $s['show_on_buyer_board'] = isset($_POST['show_on_buyer_board']) ? '1' : '0';
            if (isset($_POST['current_bid'])) {
                $oldBid = (float)($s['current_bid'] ?? 0);
                $s['current_bid'] = (float)$_POST['current_bid'];
                if ($s['current_bid'] !== $oldBid || trim((string)($s['bid_updated_at'] ?? '')) === '') $s['bid_updated_at'] = date('Y-m-d H:i');
            }
            $pNow = product_by_id($products, $s['product_id'] ?? '');
            if (isset($_POST['generate_reminder']) || trim((string)($s['reminder_draft'] ?? '')) === '') {
                $s['reminder_draft'] = reminder_draft_text($s, $pNow);
                $s['reminder_status'] = '草稿待確認';
            }
            if (isset($_POST['mark_reminded'])) {
                $s['last_reminder_at'] = date('Y-m-d H:i');
                $s['next_reminder_at'] = reminder_next_time($s);
                $s['reminder_status'] = '已產生提醒，待人工發布';
            } else {
                $s['next_reminder_at'] = trim((string)($s['next_reminder_at'] ?? '')) ?: reminder_next_time($s);
                if (schedule_needs_hourly_update($s)) $s['reminder_status'] = '需要更新';
            }
            $s['publish_note'] = trim($_POST['publish_note'] ?? ($s['publish_note'] ?? ''));
            if (isset($_POST['post_set_id'])) {
                $pickedSet = post_reply_find_set($postReplySets, trim((string)$_POST['post_set_id']));
                $s['post_set_id'] = (string)($pickedSet['id'] ?? '');
            }
            $s['updated_at'] = date('c');
            break;
        }
        unset($s);
        write_data('schedules', $schedules);
        $notice = '排程上架狀態已更新。';
        $opsInitialTab = 'schedule';
    }

    if ($action === 'save_facebook_daily_mark') {
        $opsInitialTab = 'facebook-daily';
        $id = trim((string)($_POST['schedule_id'] ?? ''));
        $mark = trim((string)($_POST['mark'] ?? ''));
        $updated = false;
        foreach ($schedules as &$s) {
            if (($s['id'] ?? '') !== $id) continue;
            if (isset($_POST['post_url'])) $s['post_url'] = safe_http_url($_POST['post_url']);
            if (isset($_POST['current_bid']) && $_POST['current_bid'] !== '') {
                $s['current_bid'] = (float)$_POST['current_bid'];
                $s['bid_updated_at'] = date('Y-m-d H:i');
            }
            if ($mark === 'posted') {
                $s['publish_status'] = '已上架';
                $s['status'] = '已上架';
                if (trim((string)($s['actual_publish_at'] ?? '')) === '') $s['actual_publish_at'] = date('Y-m-d H:i');
                $s['fb_qa_pinned'] = '1';
                $notice = '已標記發文完成，請確認貼文網址已填。';
            } elseif ($mark === 'qa') {
                $s['fb_qa_pinned'] = '1';
                $notice = '已標記問答包已置頂。';
            } elseif ($mark === 'reminded') {
                $s['last_reminder_at'] = date('Y-m-d H:i');
                $s['next_reminder_at'] = reminder_next_time($s);
                $s['reminder_status'] = '已產生提醒，待人工發布';
                $notice = '已標記價格提醒已送。';
            } elseif ($mark === 'winner') {
                $s['fb_winner_notice_sent'] = '1';
                $notice = '已標記得標通知已送。';
            } else {
                $notice = '貼文資料已更新。';
            }
            $s['updated_at'] = date('c');
            $updated = true;
            break;
        }
        unset($s);
        if ($updated) write_data('schedules', $schedules);
        else $notice = '找不到要標記的排程。';
    }

    if (in_array($action, ['save_post_reply_set', 'delete_post_reply_set', 'set_default_post_reply_set', 'restore_default_post_reply_sets', 'restore_post_reply_set', 'duplicate_post_reply_set'], true)) {
        $opsInitialTab = 'post-scripts';
    }

    if ($action === 'save_post_reply_set') {
        $setId = trim((string)($_POST['set_id'] ?? ''));
        $row = [
            'id' => $setId,
            'name' => trim((string)($_POST['set_name'] ?? '')),
            'scene' => trim((string)($_POST['set_scene'] ?? 'auction')),
            'note' => trim((string)($_POST['set_note'] ?? '')),
            'post_template' => (string)($_POST['post_template'] ?? ''),
            'qa' => post_reply_qa_from_parallel($_POST['qa_ask'] ?? [], $_POST['qa_aliases'] ?? [], $_POST['qa_answer'] ?? []),
            'is_default' => !empty($_POST['is_default']),
            'updated_at' => date('c'),
        ];
        if ($row['name'] === '') {
            $notice = '發文套組儲存失敗：請先填套組名稱。';
        } else {
            $saved = normalize_post_reply_set($row, $setId !== '');
            $updated = false;
            foreach ($postReplySets as $i => $existing) {
                if (($existing['id'] ?? '') === $saved['id']) {
                    if (empty($saved['is_default'])) $saved['is_default'] = !empty($existing['is_default']);
                    $postReplySets[$i] = $saved;
                    $updated = true;
                    break;
                }
            }
            if (!$updated) $postReplySets[] = $saved;
            if (!empty($saved['is_default']) || !empty($_POST['is_default'])) {
                $postReplySets = post_reply_mark_default($postReplySets, $saved['id']);
            }
            $postReplySets = normalize_post_reply_sets($postReplySets);
            write_data('post_reply_sets', $postReplySets);
            $notice = ($updated ? '發文問答套組已更新：' : '已新增發文問答套組：') . $saved['name'];
        }
    }

    if ($action === 'delete_post_reply_set') {
        $setId = trim((string)($_POST['set_id'] ?? ''));
        if (count($postReplySets) <= 1) {
            $notice = '至少要留一套發文問答，不能全部刪光。';
        } else {
            $kept = [];
            $removed = '';
            foreach ($postReplySets as $existing) {
                if (($existing['id'] ?? '') === $setId) {
                    $removed = (string)($existing['name'] ?? $setId);
                    continue;
                }
                $kept[] = $existing;
            }
            if ($removed === '') {
                $notice = '找不到要刪的發文套組。';
            } else {
                $postReplySets = normalize_post_reply_sets($kept);
                write_data('post_reply_sets', $postReplySets);
                $notice = '已刪除發文問答套組：' . $removed;
            }
        }
    }

    if ($action === 'set_default_post_reply_set') {
        $setId = trim((string)($_POST['set_id'] ?? ''));
        $found = post_reply_find_set($postReplySets, $setId);
        if (($found['id'] ?? '') === '') {
            $notice = '找不到要設成預設的套組。';
        } else {
            $postReplySets = post_reply_mark_default($postReplySets, (string)$found['id']);
            write_data('post_reply_sets', $postReplySets);
            $notice = '預設發文套組已改為：' . ($found['name'] ?? $found['id']);
        }
    }

    if ($action === 'duplicate_post_reply_set') {
        $source = post_reply_find_set($postReplySets, trim((string)($_POST['set_id'] ?? '')));
        $copy = $source;
        $copy['id'] = '';
        $copy['name'] = trim((string)($source['name'] ?? '套組')) . '（複本）';
        $copy['is_default'] = false;
        $copy['updated_at'] = date('c');
        $copy = normalize_post_reply_set($copy, false);
        $postReplySets[] = $copy;
        $postReplySets = normalize_post_reply_sets($postReplySets);
        write_data('post_reply_sets', $postReplySets);
        $notice = '已複製套組：' . $copy['name'];
    }

    if ($action === 'restore_post_reply_set') {
        $setId = trim((string)($_POST['set_id'] ?? ''));
        $factory = null;
        foreach (default_post_reply_sets() as $row) {
            if (($row['id'] ?? '') === $setId) { $factory = $row; break; }
        }
        if (!$factory) {
            $notice = '這套不是內建範本，沒有原廠文案可還原。';
        } else {
            $keepDefault = false;
            $updated = false;
            foreach ($postReplySets as $i => $existing) {
                if (($existing['id'] ?? '') === $setId) {
                    $keepDefault = !empty($existing['is_default']);
                    $factory['is_default'] = $keepDefault;
                    $postReplySets[$i] = normalize_post_reply_set($factory);
                    $updated = true;
                    break;
                }
            }
            if (!$updated) {
                $factory['is_default'] = false;
                $postReplySets[] = normalize_post_reply_set($factory);
            }
            $postReplySets = normalize_post_reply_sets($postReplySets);
            write_data('post_reply_sets', $postReplySets);
            $notice = '已還原內建文案：' . ($factory['name'] ?? $setId);
        }
    }

    if ($action === 'restore_default_post_reply_sets') {
        $postReplySets = normalize_post_reply_sets(default_post_reply_sets());
        write_data('post_reply_sets', $postReplySets);
        $notice = '已還原四套內建發文問答（海賊團／門市／商城／得標後）。';
    }

    if ($action === 'assign_winner_batch') {
        $ids = $_POST['winner_schedule_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
        $memberId = trim((string)($_POST['batch_member_id'] ?? ''));
        $buyer = [
            'name' => trim((string)($_POST['batch_winner'] ?? '')),
            'facebook' => trim((string)($_POST['batch_winner_facebook'] ?? '')),
            'phone' => trim((string)($_POST['batch_winner_phone'] ?? '')),
            'address' => trim((string)($_POST['batch_winner_address'] ?? '')),
        ];
        if ($memberId !== '') {
            foreach ($members as $m) {
                if (($m['id'] ?? '') !== $memberId) continue;
                foreach (['name', 'facebook', 'phone', 'address'] as $f) {
                    if ($buyer[$f] === '') $buyer[$f] = trim((string)($m[$f] ?? ''));
                }
                break;
            }
        }
        $orderStatus = trim((string)($_POST['batch_order_status'] ?? '待打單'));
        $prepStatus = trim((string)($_POST['batch_prep_status'] ?? '未備貨'));
        $updated = 0;
        if ($ids && buyer_key($buyer) !== '') {
            foreach ($schedules as &$s) {
                if (!in_array(($s['id'] ?? ''), $ids, true)) continue;
                $s['member_id'] = $memberId;
                if ($buyer['name'] !== '') $s['winner'] = $buyer['name'];
                if ($buyer['facebook'] !== '') $s['winner_facebook'] = $buyer['facebook'];
                if ($buyer['phone'] !== '') $s['winner_phone'] = $buyer['phone'];
                if ($buyer['address'] !== '') $s['winner_address'] = $buyer['address'];
                if ($orderStatus !== '') $s['order_status'] = $orderStatus;
                if ($prepStatus !== '') $s['prep_status'] = $prepStatus;
                if (trim((string)($s['payment_status'] ?? '')) === '') $s['payment_status'] = '未付款';
                if (trim((string)($s['shipping_status'] ?? '')) === '') $s['shipping_status'] = '未出貨';
                $s['updated_at'] = date('c');
                $members = upsert_member_from_schedule($members, $s, $products);
                $updated++;
            }
            unset($s);
            write_data('schedules', $schedules);
            $members = rebuild_member_stats($members, $schedules, $products);
            write_data('members', $members);
            $notice = '已將 ' . $updated . ' 筆得標品項指定給 ' . ($buyer['name'] ?: ($buyer['facebook'] ?: $buyer['phone'])) . '，並送到記單出貨。';
        } else {
            $notice = '請先勾選品項，並選擇會員或輸入得標人姓名 / Facebook / 電話。';
        }
    }

    if ($action === 'save_order_batch') {
        $ids = $_POST['order_schedule_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
        $prepStatus = trim($_POST['prep_status'] ?? '');
        $orderStatus = trim($_POST['order_status'] ?? '');
        $shippingStatus = trim($_POST['shipping_status'] ?? '');
        $shippingDate = trim($_POST['shipping_date'] ?? '');
        $trackingNo = trim($_POST['tracking_no'] ?? '');
        $logisticsCompany = trim($_POST['logistics_company'] ?? '');
        $updated = 0;
        foreach ($schedules as &$s) {
            if (!in_array(($s['id'] ?? ''), $ids, true)) continue;
            if ($prepStatus !== '') $s['prep_status'] = $prepStatus;
            if ($orderStatus !== '') $s['order_status'] = $orderStatus;
            if ($shippingStatus !== '') $s['shipping_status'] = $shippingStatus;
            if ($shippingDate !== '') $s['shipping_date'] = $shippingDate;
            if ($trackingNo !== '') $s['tracking_no'] = $trackingNo;
            if ($logisticsCompany !== '') $s['logistics_company'] = $logisticsCompany;
            $s['updated_at'] = date('c');
            $updated++;
        }
        unset($s);
        write_data('schedules', $schedules);
        $members = rebuild_member_stats($members, $schedules, $products);
        write_data('members', $members);
        $notice = '記單出貨已更新 ' . $updated . ' 筆得標品項。';
    }

    if ($action === 'create_delivery_note') {
        $ids = $_POST['delivery_schedule_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
        if (!$ids) {
            $notice = '請先選擇要轉出貨單的得標品項。';
        } else {
            $deliveryNo = next_delivery_no($deliveryNotes, 'sell');
            $items = [];
            $buyer = ['name' => '', 'facebook' => '', 'phone' => '', 'address' => ''];
            $total = 0;
            foreach ($schedules as &$s) {
                if (!in_array(($s['id'] ?? ''), $ids, true)) continue;
                $p = product_by_id($products, $s['product_id'] ?? '');
                $t = totals($s, $p);
                if ($buyer['name'] === '') {
                    $buyer = [
                        'name' => trim((string)($s['winner'] ?? '')),
                        'facebook' => trim((string)($s['winner_facebook'] ?? '')),
                        'phone' => trim((string)($s['winner_phone'] ?? '')),
                        'address' => trim((string)($s['winner_address'] ?? '')),
                    ];
                }
                $s['delivery_no'] = $deliveryNo;
                $s['order_status'] = in_array(($s['order_status'] ?? ''), ['已出貨', '完成'], true) ? ($s['order_status'] ?? '') : '待出貨';
                $s['shipping_status'] = in_array(($s['shipping_status'] ?? ''), ['已出貨', '完成'], true) ? ($s['shipping_status'] ?? '') : '備貨中';
                $s['delivery_created_at'] = date('c');
                $s['updated_at'] = date('c');
                $items[] = [
                    'schedule_id' => $s['id'] ?? '',
                    'product_id' => $s['product_id'] ?? '',
                    'product_barcode' => $s['product_barcode'] ?? ($p['barcode'] ?? ''),
                    'product_title' => $s['product_title'] ?? ($p['title'] ?? ''),
                    'quantity' => max(1, (int)($s['quantity'] ?? 1)),
                    'receivable' => $t['receivable'],
                    'image' => $s['selected_image'] ?? ($p['image'] ?? ''),
                ];
                $total += $t['receivable'];
            }
            unset($s);
            $deliveryNotes[] = [
                'id' => uid('dn_'),
                'delivery_no' => $deliveryNo,
                'buyer' => $buyer,
                'schedule_ids' => $ids,
                'items' => $items,
                'total' => $total,
                'status' => '待出貨',
                'created_at' => date('c'),
            ];
            write_data('schedules', $schedules);
            write_data('delivery_notes', $deliveryNotes);
            $members = rebuild_member_stats($members, $schedules, $products);
            write_data('members', $members);
            $notice = '已產生出貨單 ' . $deliveryNo . '，共 ' . count($items) . ' 筆品項。';
        }
    }

    if ($action === 'sales_out') {
        $salesItems = $_POST['sales_items'] ?? [];
        if (!is_array($salesItems)) $salesItems = [];
        $editId = trim((string)($_POST['sales_delivery_id'] ?? ''));
        $existingIdx = $editId !== '' ? find_delivery_note_index($deliveryNotes, $editId) : -1;
        $existingNote = $existingIdx >= 0 ? $deliveryNotes[$existingIdx] : null;
        $salesDate = trim((string)($_POST['sales_doc_date'] ?? date('Y-m-d')));
        $customerName = trim((string)($_POST['sales_customer_name'] ?? ''));
        $customerFacebook = trim((string)($_POST['sales_customer_facebook'] ?? ''));
        $customerPhone = trim((string)($_POST['sales_customer_phone'] ?? ''));
        $customerAddress = trim((string)($_POST['sales_customer_address'] ?? ''));
        $salesHandler = trim((string)($_POST['sales_handler'] ?? current_operator()));
        $salesDepartment = trim((string)($_POST['sales_department'] ?? '電商部'));
        $paymentStatus = trim((string)($_POST['sales_payment_status'] ?? '未付款'));
        $shippingStatus = trim((string)($_POST['sales_shipping_status'] ?? '未出貨'));
        $logisticsCompany = trim((string)($_POST['sales_logistics_company'] ?? ''));
        $trackingNo = trim((string)($_POST['sales_tracking_no'] ?? ''));
        $invoiceNo = trim((string)($_POST['sales_invoice_no'] ?? ''));
        $discount = max(0, (float)($_POST['sales_discount'] ?? 0));
        $tax = max(0, (float)($_POST['sales_tax'] ?? 0));
        $shippingFee = max(0, (float)($_POST['sales_shipping_fee'] ?? 0));
        $otherFee = max(0, (float)($_POST['sales_other_fee'] ?? 0));
        $docNote = trim((string)($_POST['sales_doc_note'] ?? ''));
        $opsInitialTab = 'customer-shipping';
        $qtyBonus = delivery_note_qty_bonus(is_array($existingNote['items'] ?? null) ? $existingNote['items'] : []);
        $items = [];
        $lineTotal = 0;
        $skipped = 0;
        foreach ($salesItems as $item) {
            if (!is_array($item)) { $skipped++; continue; }
            $key = trim((string)($item['product_key'] ?? ''));
            $qty = max(0, (int)($item['qty'] ?? 0));
            $unitPrice = max(0, (float)number_value($item['unit_price'] ?? 0));
            $idx = find_product_key($products, $key);
            if ($idx < 0 || $qty <= 0) { $skipped++; continue; }
            $productId = strtoupper(trim((string)($products[$idx]['id'] ?? $key)));
            $available = stock_available($products[$idx]) + (int)($qtyBonus[$productId] ?? 0);
            if ($available < $qty) { $skipped++; continue; }
            $lineSubtotal = $qty * $unitPrice;
            $lineTotal += $lineSubtotal;
            $items[] = [
                'product_id' => $products[$idx]['id'] ?? $key,
                'product_barcode' => $products[$idx]['barcode'] ?? '',
                'product_title' => $products[$idx]['title'] ?? '',
                'color' => $products[$idx]['color'] ?? '',
                'size' => $products[$idx]['size'] ?? '',
                'spec' => $products[$idx]['spec'] ?? '',
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'unit_cost' => (float)($products[$idx]['cost'] ?? 0),
                'line_subtotal' => $lineSubtotal,
                'warehouse_name' => $products[$idx]['warehouse_name'] ?? '',
                'shelf_code' => $products[$idx]['shelf_code'] ?? '',
                'warehouse_location' => $products[$idx]['warehouse_location'] ?? '',
                'note' => trim((string)($item['note'] ?? '')),
            ];
        }
        if ($customerName === '') {
            $notice = '銷售出庫儲存失敗：請填客戶名稱，出貨單必須看得出是哪一位客戶。';
            if ($existingNote) $salesOutKeepEditId = $editId;
        } elseif (!$items) {
            $notice = '銷售出庫儲存失敗：請加入產品明細，並確認庫存足夠。';
            if ($existingNote) $salesOutKeepEditId = $editId;
        } else {
            $oldNo = (string)($existingNote['delivery_no'] ?? '');
            $shipKind = (($existingNote['source'] ?? '') === 'quotation') ? 'val' : 'sell';
            $deliveryNo = assign_sales_delivery_no($_POST['sales_doc_no'] ?? '', $deliveryNotes, $oldNo, $shipKind);
            if ($existingNote && delivery_note_should_adjust_stock($existingNote, $stockMovements)) {
                restore_delivery_note_stock($products, $existingNote['items'] ?? []);
                $stockMovements = strip_stock_movements_for_delivery($stockMovements, $oldNo);
                if ($deliveryNo !== $oldNo) $stockMovements = strip_stock_movements_for_delivery($stockMovements, $deliveryNo);
            }
            foreach ($items as $line) {
                $idx = find_product_key($products, (string)($line['product_id'] ?? ''));
                if ($idx < 0) continue;
                $qty = (int)($line['quantity'] ?? 0);
                $unitPrice = (float)($line['unit_price'] ?? 0);
                $lineSubtotal = (float)($line['line_subtotal'] ?? 0);
                $products[$idx]['stock_sold'] = (int)($products[$idx]['stock_sold'] ?? 0) + $qty;
                $products[$idx]['last_stock_out_qty'] = $qty;
                $products[$idx]['last_stock_out_at'] = date('c');
                $products[$idx]['updated_at'] = date('c');
                $stockMovements[] = [
                    'id' => uid('sale_'),
                    'type' => '銷售出庫單',
                    'document_no' => $deliveryNo,
                    'source_doc_no' => $deliveryNo,
                    'source_doc_type' => '銷售出庫單',
                    'date' => $salesDate,
                    'document_date' => $salesDate,
                    'product_id' => $products[$idx]['id'] ?? ($line['product_id'] ?? ''),
                    'barcode' => $products[$idx]['barcode'] ?? '',
                    'product_title' => $products[$idx]['title'] ?? '',
                    'qty' => -1 * $qty,
                    'unit_cost' => (float)($products[$idx]['cost'] ?? 0),
                    'unit_price' => $unitPrice,
                    'amount' => $lineSubtotal,
                    'total_amount' => $lineSubtotal,
                    'party_name' => $customerName,
                    'customer_name' => $customerName,
                    'handler' => $salesHandler,
                    'department' => $salesDepartment,
                    'payment_status' => $paymentStatus,
                    'shipping_status' => $shippingStatus,
                    'logistics_company' => $logisticsCompany,
                    'tracking_no' => $trackingNo,
                    'invoice_no' => $invoiceNo,
                    'warehouse_name' => $products[$idx]['warehouse_name'] ?? '',
                    'shelf_code' => $products[$idx]['shelf_code'] ?? '',
                    'warehouse_location' => $products[$idx]['warehouse_location'] ?? '',
                    'summary' => '銷售出庫給 ' . $customerName,
                    'source' => '銷售出庫單',
                    'operator' => current_operator(),
                    'created_at' => date('c'),
                    'updated_at' => date('c'),
                ];
            }
            $total = max(0, $lineTotal - $discount + $tax + $shippingFee + $otherFee);
            $noteRow = [
                'id' => $existingNote['id'] ?? uid('dn_'),
                'delivery_no' => $deliveryNo,
                'source' => $existingNote['source'] ?? 'sales_out',
                'quote_no' => $existingNote['quote_no'] ?? '',
                'date' => $salesDate,
                'buyer' => [
                    'name' => $customerName,
                    'facebook' => $customerFacebook,
                    'phone' => $customerPhone,
                    'address' => $customerAddress,
                ],
                'items' => $items,
                'line_total' => $lineTotal,
                'discount' => $discount,
                'tax' => $tax,
                'shipping_fee' => $shippingFee,
                'other_fee' => $otherFee,
                'total' => $total,
                'status' => $shippingStatus,
                'payment_status' => $paymentStatus,
                'shipping_status' => $shippingStatus,
                'logistics_company' => $logisticsCompany,
                'tracking_no' => $trackingNo,
                'invoice_no' => $invoiceNo,
                'handler' => $salesHandler,
                'department' => $salesDepartment,
                'note' => $docNote,
                'operator' => current_operator(),
                'created_at' => $existingNote['created_at'] ?? date('c'),
                'updated_at' => date('c'),
            ];
            if ($existingIdx >= 0) $deliveryNotes[$existingIdx] = $noteRow;
            else $deliveryNotes[] = $noteRow;
            write_data('products', $products);
            write_data('stock_movements', $stockMovements);
            write_data('delivery_notes', $deliveryNotes);
            $notice = ($existingNote ? '銷售出庫單已更新：' : '銷售出庫單已建立：') . $deliveryNo . '，客戶 ' . $customerName . '，共 ' . count($items) . ' 筆明細。';
            if ($skipped > 0) $notice .= ' 略過 ' . $skipped . ' 筆庫存不足或資料不完整明細。';
        }
    }

    if ($action === 'delete_delivery_note') {
        $deleteId = trim((string)($_POST['delivery_id'] ?? ''));
        $opsInitialTab = 'customer-shipping';
        $idx = find_delivery_note_index($deliveryNotes, $deleteId);
        if ($idx < 0) {
            $notice = '刪除失敗：找不到這張出貨單。';
        } else {
            $existingNote = $deliveryNotes[$idx];
            $oldNo = (string)($existingNote['delivery_no'] ?? '');
            if (delivery_note_should_adjust_stock($existingNote, $stockMovements)) {
                restore_delivery_note_stock($products, $existingNote['items'] ?? []);
                $stockMovements = strip_stock_movements_for_delivery($stockMovements, $oldNo);
                write_data('products', $products);
                write_data('stock_movements', $stockMovements);
            }
            array_splice($deliveryNotes, $idx, 1);
            write_data('delivery_notes', $deliveryNotes);
            $notice = '出貨單已刪除' . ($oldNo !== '' ? '：' . $oldNo : '') . '，已回補該單扣過的庫存。';
        }
    }

    if ($action === 'apply_payment_offset') {
        $ids = $_POST['payment_schedule_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids))));
        $amount = max(0, (float)($_POST['payment_amount'] ?? 0));
        $paidAt = trim($_POST['payment_paid_at'] ?? '');
        $method = trim($_POST['payment_method'] ?? '匯款');
        $note = trim($_POST['payment_note'] ?? '');
        $remaining = $amount;
        $applied = 0;
        $allocations = [];
        foreach ($schedules as &$s) {
            if ($remaining <= 0) break;
            if (!in_array(($s['id'] ?? ''), $ids, true)) continue;
            $p = product_by_id($products, $s['product_id'] ?? '');
            $t = totals($s, $p);
            $pay = min($remaining, $t['unpaid']);
            if ($pay <= 0) continue;
            $s['paid_amount'] = (float)($s['paid_amount'] ?? 0) + $pay;
            $s['payment_date'] = $paidAt;
            $newTotals = totals($s, $p);
            $s['payment_status'] = $newTotals['unpaid'] <= 0 ? '已付款' : '部分付款';
            $s['payment_history'] = $s['payment_history'] ?? [];
            if (!is_array($s['payment_history'])) $s['payment_history'] = [];
            $historyRow = [
                'id' => uid('pay_'),
                'paid_at' => $paidAt,
                'amount' => $pay,
                'method' => $method,
                'note' => $note,
                'created_at' => date('c'),
            ];
            $s['payment_history'][] = $historyRow;
            $allocations[] = ['schedule_id' => $s['id'] ?? '', 'product_id' => $s['product_id'] ?? '', 'amount' => $pay];
            $remaining -= $pay;
            $applied += $pay;
            $s['updated_at'] = date('c');
        }
        unset($s);
        $paymentRecords[] = [
            'id' => uid('pay_batch_'),
            'paid_at' => $paidAt,
            'amount' => $amount,
            'applied_amount' => $applied,
            'remaining_amount' => $remaining,
            'method' => $method,
            'note' => $note,
            'allocations' => $allocations,
            'created_at' => date('c'),
        ];
        $members = rebuild_member_stats($members, $schedules, $products);
        write_data('schedules', $schedules);
        write_data('members', $members);
        write_data('payment_records', $paymentRecords);
        $notice = '收款沖帳完成：本次匯款 ' . money($amount) . '，已沖 ' . money($applied) . ($remaining > 0 ? '，尚未分配 ' . money($remaining) : '') . '。';
    }

    if ($action === 'save_settlement') {
        foreach ($schedules as &$s) {
            if (($s['id'] ?? '') !== ($_POST['schedule_id'] ?? '')) continue;
            $upload = upload_image('schedule_image_upload', ($s['product_id'] ?? 'schedule'), 'uploads/schedules');
            foreach (['status','order_status','post_url','schedule_image','schedule_image_note','member_id','winner','winner_facebook','winner_phone','winner_address','fee_type','payment_status','shipping_status','payment_date','shipping_date','tracking_no','logistics_company','product_serial','warranty_serial','invoice_no','settlement_note','winner_notice_message','auction_notice_message'] as $f) {
                if (isset($_POST[$f])) $s[$f] = trim((string)$_POST[$f]);
            }
            if ($upload) $s['schedule_image'] = $upload;
            ensure_order_token($s);
            if (isset($s['post_url'])) $s['post_url'] = safe_http_url($s['post_url']);
            foreach (['winning_price','paid_amount','quantity','product_cost','fee_value','shipping_fee','other_fee'] as $f) {
                if (isset($_POST[$f])) $s[$f] = (float)$_POST[$f];
            }
            $s['updated_by'] = current_operator();
            $s['updated_at'] = date('c');
            $s['tax_included'] = (($_POST['tax_included'] ?? '0') === '1') ? '1' : '0';
            if ($s['tax_included'] === '1') { $s['fee_type'] = 'percent'; $s['fee_value'] = 5; }
            $tNow = totals($s, product_by_id($products, $s['product_id'] ?? ''));
            $pNow = product_by_id($products, $s['product_id'] ?? '');
            if (trim((string)($s['winner_notice_message'] ?? '')) === '') {
                $link = buyer_order_url($s);
                $s['winner_notice_message'] = winner_notice_text($s, $pNow, $tNow) . ($link ? "\n訂單填寫/查詢連結：" . $link : '');
            }
            if (trim((string)($s['auction_notice_message'] ?? '')) === '') $s['auction_notice_message'] = auction_notice_text($s, $pNow, $tNow);
            $s['quantity'] = max(1, (int)($s['quantity'] ?? 1));
            $s['updated_at'] = date('c');
            break;
        }
        unset($s);
        $members = upsert_member_from_schedule($members, schedule_by_id($schedules, $_POST['schedule_id'] ?? ''), $products);
        write_data('schedules', $schedules);
        write_data('members', $members);
        $notice = '得標、會員與記單資料已更新。';
    }

    if ($action === 'save_supplier') {
        $id = trim($_POST['supplier_id'] ?? '') ?: uid('sup_');
        $row = [
            'id' => $id,
            'name' => trim($_POST['supplier_name'] ?? ''),
            'contact' => trim($_POST['supplier_contact'] ?? ''),
            'phone' => trim($_POST['supplier_phone'] ?? ''),
            'tax_id' => trim($_POST['supplier_tax_id'] ?? ''),
            'address' => trim($_POST['supplier_address'] ?? ''),
            'note' => trim($_POST['supplier_note'] ?? ''),
            'created_at' => trim($_POST['created_at'] ?? '') ?: date('c'),
            'updated_at' => date('c'),
            'operator' => current_operator(),
        ];
        if ($row['name'] === '') {
            $notice = '廠商建檔失敗：請輸入廠商名稱。';
        } else {
            $found = false;
            foreach ($suppliers as &$sp) {
                if (($sp['id'] ?? '') === $id) {
                    $row['created_at'] = $sp['created_at'] ?? $row['created_at'];
                    $sp = array_merge($sp, $row);
                    $found = true;
                    break;
                }
            }
            unset($sp);
            if (!$found) $suppliers[] = $row;
            write_data('suppliers', $suppliers);
            $notice = '廠商資料已儲存。';
        }
    }

    if ($action === 'delete_supplier') {
        $id = trim($_POST['supplier_id'] ?? '');
        $before = count($suppliers);
        $suppliers = array_values(array_filter($suppliers, function($s) use ($id) { return ($s['id'] ?? '') !== $id; }));
        write_data('suppliers', $suppliers);
        $notice = count($suppliers) < $before ? '廠商資料已刪除。' : '找不到要刪除的廠商。';
    }

    if ($action === 'delete_suppliers') {
        $ids = array_values(array_filter(array_map('trim', (array)($_POST['supplier_ids'] ?? []))));
        if (!$ids) {
            $notice = '請先勾選要刪除的廠商。';
        } else {
            $before = count($suppliers);
            $suppliers = array_values(array_filter($suppliers, function($supplier) use ($ids) {
                return !in_array((string)($supplier['id'] ?? ''), $ids, true);
            }));
            $deleted = $before - count($suppliers);
            if ($deleted > 0) write_data('suppliers', $suppliers);
            $notice = $deleted > 0 ? '已刪除勾選的廠商，共 ' . $deleted . ' 筆。' : '找不到要刪除的廠商。';
        }
    }

    if ($action === 'save_member') {
        $id = trim($_POST['member_id'] ?? '') ?: uid('m_');
        $row = [
            'id' => $id,
            'name' => trim($_POST['name'] ?? ''),
            'facebook' => trim($_POST['facebook'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'blacklist_status' => trim($_POST['blacklist_status'] ?? '正常'),
            'risk_level' => trim($_POST['risk_level'] ?? '一般'),
            'blacklist_reason' => trim($_POST['blacklist_reason'] ?? ''),
            'note' => trim($_POST['note'] ?? ''),
            'created_at' => trim($_POST['created_at'] ?? '') ?: date('c'),
            'updated_at' => date('c'),
        ];
        $found = false;
        foreach ($members as &$m) if (($m['id'] ?? '') === $id) { $row['created_at'] = $m['created_at'] ?? $row['created_at']; $m = array_merge($m, $row); $found = true; break; }
        unset($m);
        if (!$found) $members[] = $row;
        $members = rebuild_member_stats($members, $schedules, $products);
        write_data('members', $members);
        $notice = '會員資料已儲存。';
    }

    if ($action === 'create_return') {
        $schedule = schedule_by_id($schedules, $_POST['schedule_id'] ?? '');
        $returns[] = [
            'id' => uid('ret_'),
            'return_no' => ops_next_doc_no('back_c', array_merge(ops_collect_doc_nos($returns), ops_collect_doc_nos($stockMovements))),
            'schedule_id' => $_POST['schedule_id'] ?? '',
            'product_id' => $schedule['product_id'] ?? '',
            'member_name' => $schedule['winner'] ?? '',
            'member_facebook' => $schedule['winner_facebook'] ?? '',
            'member_phone' => $schedule['winner_phone'] ?? '',
            'return_qty' => max(1, (int)($_POST['return_qty'] ?? 1)),
            'reason' => trim($_POST['reason'] ?? ''),
            'condition_note' => trim($_POST['condition_note'] ?? ''),
            'refund_amount' => (float)($_POST['refund_amount'] ?? 0),
            'status' => $_POST['return_status'] ?? '申請中',
            'received_date' => $_POST['received_date'] ?? '',
            'handled_date' => $_POST['handled_date'] ?? '',
            'solution' => $_POST['solution'] ?? '待判斷',
            'note' => trim($_POST['note'] ?? ''),
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        foreach ($schedules as &$s) if (($s['id'] ?? '') === ($_POST['schedule_id'] ?? '')) { $s['return_status'] = $_POST['return_status'] ?? '申請中'; $s['order_status'] = '退回處理'; }
        unset($s);
        write_data('returns', $returns);
        write_data('schedules', $schedules);
        $notice = '退回紀錄已建立。';
    }

    if ($action === 'save_return') {
        foreach ($returns as &$r) {
            if (($r['id'] ?? '') !== ($_POST['return_id'] ?? '')) continue;
            foreach (['status','received_date','handled_date','solution','reason','condition_note','note'] as $f) if (isset($_POST[$f])) $r[$f] = trim((string)$_POST[$f]);
            foreach (['return_qty','refund_amount'] as $f) if (isset($_POST[$f])) $r[$f] = (float)$_POST[$f];
            $r['updated_at'] = date('c');
            if (in_array(($r['status'] ?? ''), ['已退款','完成'], true) && empty($r['movement_id'])) {
                $qty = max(1, (int)($r['return_qty'] ?? 1));
                $productKey = trim((string)($r['product_id'] ?? ''));
                $idx = find_product_key($products, $productKey);
                $unitCost = 0;
                $barcode = '';
                $title = '';
                if ($idx >= 0) {
                    $unitCost = (float)($products[$idx]['cost'] ?? 0);
                    $barcode = (string)($products[$idx]['barcode'] ?? '');
                    $title = (string)($products[$idx]['title'] ?? '');
                    $products[$idx]['stock_total'] = (int)($products[$idx]['stock_total'] ?? 0) + $qty;
                    $products[$idx]['updated_at'] = date('c');
                }
                $returnNo = trim((string)($r['return_no'] ?? ''));
                if ($returnNo === '' || !ops_doc_no_is_valid($returnNo, 'back_c')) {
                    $returnNo = ops_next_doc_no('back_c', array_merge(ops_collect_doc_nos($returns), ops_collect_doc_nos($stockMovements)));
                }
                $movementId = uid('srt_');
                $stockMovements[] = [
                    'id' => $movementId,
                    'type' => '銷售退貨單',
                    'document_no' => $returnNo,
                    'source_doc_no' => $returnNo,
                    'source_doc_type' => '銷售退貨單',
                    'date' => $r['handled_date'] ?: date('Y-m-d'),
                    'document_date' => $r['handled_date'] ?: date('Y-m-d'),
                    'product_id' => $productKey,
                    'barcode' => $barcode,
                    'product_title' => $title,
                    'qty' => $qty,
                    'unit_cost' => $unitCost,
                    'amount' => (float)($r['refund_amount'] ?? 0),
                    'total_amount' => (float)($r['refund_amount'] ?? 0),
                    'party_name' => $r['member_name'] ?? '',
                    'customer_name' => $r['member_name'] ?? '',
                    'reason' => $r['reason'] ?? '',
                    'summary' => '銷售退回：' . ($r['reason'] ?? ''),
                    'source' => '銷售退貨單',
                    'operator' => current_operator(),
                    'created_at' => date('c'),
                    'updated_at' => date('c'),
                ];
                $r['return_no'] = $returnNo;
                $r['movement_id'] = $movementId;
            }
        }
        unset($r);
        write_data('returns', $returns);
        write_data('products', $products);
        write_data('stock_movements', $stockMovements);
        $notice = '退回處理已更新。';
    }

    if ($action === 'create_repair_document') {
        $repairCreatedAt = trim($_POST['created_at'] ?? '') ?: date('Y-m-d H:i');
        $repairDocuments[] = [
            'id' => uid('repair_'),
            'repair_no' => next_repair_no($repairDocuments, $repairCreatedAt),
            'item' => trim($_POST['item'] ?? ''),
            'contact_name' => trim($_POST['contact_name'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'technician' => trim($_POST['technician'] ?? ''),
            'registrar' => trim($_POST['registrar'] ?? '') ?: current_operator(),
            'estimated_fee' => (float)($_POST['estimated_fee'] ?? 0),
            'settlement_status' => trim($_POST['settlement_status'] ?? '未結算'),
            'repair_status' => trim($_POST['repair_status'] ?? '待檢測'),
            'repair_condition' => trim($_POST['repair_condition'] ?? ''),
            'exclusion_condition' => trim($_POST['exclusion_condition'] ?? ''),
            'created_at' => $repairCreatedAt,
            'note' => trim($_POST['note'] ?? ''),
            'updated_at' => date('c'),
        ];
        write_data('repair_documents', $repairDocuments);
        $notice = '維修單據已建立。';
    }

    if ($action === 'save_repair_document') {
        foreach ($repairDocuments as &$r) {
            if (($r['id'] ?? '') !== ($_POST['repair_id'] ?? '')) continue;
            foreach (['item','contact_name','phone','address','technician','registrar','settlement_status','repair_status','repair_condition','exclusion_condition','created_at','note'] as $f) {
                if (isset($_POST[$f])) $r[$f] = trim((string)$_POST[$f]);
            }
            if (isset($_POST['estimated_fee'])) $r['estimated_fee'] = (float)$_POST['estimated_fee'];
            $r['updated_at'] = date('c');
        }
        unset($r);
        write_data('repair_documents', $repairDocuments);
        $notice = '維修單據已更新。';
    }

    if ($action === 'delete_repair_document') {
        $id = trim($_POST['repair_id'] ?? '');
        $repairDocuments = array_values(array_filter($repairDocuments, function($r) use ($id) { return ($r['id'] ?? '') !== $id; }));
        write_data('repair_documents', $repairDocuments);
        $notice = '維修單據已刪除。';
    }

    if ($action === 'delete_repair_documents') {
        $ids = $_POST['repair_ids'] ?? [];
        $repairDocuments = array_values(array_filter($repairDocuments, function($r) use ($ids) { return !in_array($r['id'] ?? '', $ids, true); }));
        write_data('repair_documents', $repairDocuments);
        $notice = '已刪除勾選維修單據。';
    }


    if ($action === 'save_finance_other_income') {
        $id = trim($_POST['other_income_id'] ?? '') ?: uid('foi_');
        $row = [
            'id' => $id,
            'account_code' => trim($_POST['account_code'] ?? ''),
            'name' => trim($_POST['name'] ?? ''),
            'short_name' => trim($_POST['short_name'] ?? ''),
            'pinyin' => trim($_POST['pinyin'] ?? ''),
            'ending_balance' => (float)($_POST['ending_balance'] ?? 0),
            'income_type' => trim($_POST['income_type'] ?? '其他收入'),
            'link_source' => trim($_POST['link_source'] ?? '手動核對'),
            'frequency' => trim($_POST['frequency'] ?? '依發生'),
            'status' => trim($_POST['status'] ?? '啟用'),
            'note' => trim($_POST['note'] ?? ''),
            'updated_at' => date('c'),
        ];
        $found = false;
        foreach ($financeOtherIncome as &$old) {
            if (($old['id'] ?? '') === $id) {
                $row['created_at'] = $old['created_at'] ?? date('c');
                $old = array_merge($old, $row);
                $found = true;
                break;
            }
        }
        unset($old);
        if (!$found) {
            $row['created_at'] = date('c');
            $financeOtherIncome[] = $row;
        }
        usort($financeOtherIncome, function($a, $b) { return strnatcasecmp((string)($a['account_code'] ?? ''), (string)($b['account_code'] ?? '')); });
        write_data('finance_other_income', $financeOtherIncome);
        $notice = '其他收入科目已儲存。';
    }

    if ($action === 'delete_finance_other_income') {
        $id = trim($_POST['other_income_id'] ?? '');
        $financeOtherIncome = array_values(array_filter($financeOtherIncome, function($r) use ($id) { return ($r['id'] ?? '') !== $id; }));
        write_data('finance_other_income', $financeOtherIncome);
        $notice = '其他收入科目已刪除。';
    }

    if ($action === 'delete_finance_other_incomes') {
        $ids = $_POST['other_income_ids'] ?? [];
        $financeOtherIncome = array_values(array_filter($financeOtherIncome, function($r) use ($ids) { return !in_array($r['id'] ?? '', $ids, true); }));
        write_data('finance_other_income', $financeOtherIncome);
        $notice = '已刪除勾選其他收入科目。';
    }

    if ($action === 'save_finance_expense_category') {
        $id = trim($_POST['expense_category_id'] ?? '') ?: uid('fec_');
        $row = [
            'id' => $id,
            'account_code' => trim($_POST['account_code'] ?? ''),
            'name' => trim($_POST['name'] ?? ''),
            'short_name' => trim($_POST['short_name'] ?? ''),
            'pinyin' => trim($_POST['pinyin'] ?? ''),
            'ending_balance' => (float)($_POST['ending_balance'] ?? 0),
            'status' => trim($_POST['status'] ?? '啟用'),
            'expense_type' => trim($_POST['expense_type'] ?? '一般支出'),
            'link_source' => trim($_POST['link_source'] ?? '手動核對'),
            'frequency' => trim($_POST['frequency'] ?? '依發生'),
            'is_fixed_expense' => isset($_POST['is_fixed_expense']),
            'expense_type' => trim($_POST['expense_type'] ?? '一般支出'),
            'link_source' => trim($_POST['link_source'] ?? '手動核對'),
            'frequency' => trim($_POST['frequency'] ?? '依發生'),
            'is_fixed_expense' => isset($_POST['is_fixed_expense']),
            'note' => trim($_POST['note'] ?? ''),
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        $found = false;
        foreach ($financeExpenseCategories as &$fec) {
            if (($fec['id'] ?? '') === $id || (($row['account_code'] !== '') && (($fec['account_code'] ?? '') === $row['account_code']))) {
                $row['id'] = $fec['id'] ?? $id;
                $row['created_at'] = $fec['created_at'] ?? $row['created_at'];
                $fec = array_merge($fec, $row);
                $found = true;
                break;
            }
        }
        unset($fec);
        if (!$found) $financeExpenseCategories[] = $row;
        usort($financeExpenseCategories, function($a, $b) { return strnatcasecmp((string)($a['account_code'] ?? ''), (string)($b['account_code'] ?? '')); });
        write_data('finance_expense_categories', $financeExpenseCategories);
        $notice = '費用支出科目已儲存。';
    }

    if ($action === 'delete_finance_expense_category') {
        $id = trim($_POST['expense_category_id'] ?? '');
        $financeExpenseCategories = array_values(array_filter($financeExpenseCategories, function($r) use ($id) { return ($r['id'] ?? '') !== $id; }));
        write_data('finance_expense_categories', $financeExpenseCategories);
        $notice = '費用支出科目已刪除。';
    }

    if ($action === 'delete_finance_expense_categories') {
        $ids = $_POST['expense_category_ids'] ?? [];
        $financeExpenseCategories = array_values(array_filter($financeExpenseCategories, function($r) use ($ids) { return !in_array($r['id'] ?? '', $ids, true); }));
        write_data('finance_expense_categories', $financeExpenseCategories);
        $notice = '已刪除勾選費用支出科目。';
    }

    if ($action === 'save_fixed_expense') {
        $id = trim((string)($_POST['fixed_expense_id'] ?? '')) ?: uid('fe_');
        $name = trim((string)($_POST['name'] ?? ''));
        $categoryId = trim((string)($_POST['expense_category_id'] ?? ''));
        $category = null;
        foreach ($financeExpenseCategories as $candidate) {
            if (($candidate['id'] ?? '') === $categoryId) { $category = $candidate; break; }
        }
        $supplierId = trim((string)($_POST['supplier_id'] ?? ''));
        $supplier = null;
        foreach ($suppliers as $candidate) {
            if (($candidate['id'] ?? '') === $supplierId) { $supplier = $candidate; break; }
        }
        if ($name === '' || !$category) {
            $notice = '固定開支儲存失敗：請輸入項目名稱並選擇費用科目。';
        } else {
            $row = [
                'id' => $id,
                'name' => $name,
                'expense_category_id' => $categoryId,
                'account_code' => $category['account_code'] ?? '',
                'category_name' => $category['name'] ?? '',
                'supplier_id' => $supplierId,
                'supplier_name' => $supplier['name'] ?? trim((string)($_POST['supplier_name'] ?? '')),
                'frequency' => trim((string)($_POST['frequency'] ?? '每月')),
                'amount_type' => trim((string)($_POST['amount_type'] ?? '固定金額')),
                'default_amount' => max(0, (float)($_POST['default_amount'] ?? 0)),
                'payment_day' => max(1, min(31, (int)($_POST['payment_day'] ?? 1))),
                'start_date' => trim((string)($_POST['start_date'] ?? date('Y-m-d'))),
                'end_date' => trim((string)($_POST['end_date'] ?? '')),
                'payment_method' => trim((string)($_POST['payment_method'] ?? '轉帳')),
                'account_name' => trim((string)($_POST['account_name'] ?? '')),
                'cost_center' => trim((string)($_POST['cost_center'] ?? '')),
                'status' => trim((string)($_POST['status'] ?? '啟用')),
                'auto_generate' => isset($_POST['auto_generate']),
                'note' => trim((string)($_POST['note'] ?? '')),
                'operator' => current_operator(),
                'updated_at' => date('c'),
            ];
            $found = false;
            foreach ($fixedExpenses as &$fixedExpense) {
                if (($fixedExpense['id'] ?? '') === $id) {
                    $row['created_at'] = $fixedExpense['created_at'] ?? date('c');
                    $fixedExpense = array_merge($fixedExpense, $row);
                    $found = true;
                    break;
                }
            }
            unset($fixedExpense);
            if (!$found) {
                $row['created_at'] = date('c');
                $fixedExpenses[] = $row;
            }
            foreach ($financeExpenseCategories as &$expenseCategory) {
                if (($expenseCategory['id'] ?? '') === $categoryId) {
                    $expenseCategory['is_fixed_expense'] = true;
                    $expenseCategory['updated_at'] = date('c');
                    break;
                }
            }
            unset($expenseCategory);
            write_data('fixed_expenses', $fixedExpenses);
            write_data('finance_expense_categories', $financeExpenseCategories);
            $notice = '固定開支設定已儲存，系統會依週期建立應付紀錄。';
        }
    }

    if ($action === 'delete_fixed_expense') {
        $id = trim((string)($_POST['fixed_expense_id'] ?? ''));
        $fixedExpenses = array_values(array_filter($fixedExpenses, function($row) use ($id) { return ($row['id'] ?? '') !== $id; }));
        write_data('fixed_expenses', $fixedExpenses);
        $notice = '固定開支設定已刪除；既有付款歷史已保留。';
    }

    if ($action === 'generate_fixed_expense_period') {
        $period = trim((string)($_POST['fixed_expense_period'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $notice = '產生失敗：月份格式不正確。';
        } else {
            [$fixedExpensePayments, $createdCount] = generate_fixed_expense_payments($fixedExpenses, $fixedExpensePayments, $period, false);
            if ($createdCount > 0) write_data('fixed_expense_payments', $fixedExpensePayments);
            $notice = '指定月份應付紀錄已完成，共新增 ' . $createdCount . ' 筆；重複項目已略過。';
        }
    }

    if ($action === 'save_fixed_expense_payment') {
        $id = trim((string)($_POST['fixed_expense_payment_id'] ?? ''));
        $found = false;
        foreach ($fixedExpensePayments as &$payment) {
            if (($payment['id'] ?? '') !== $id) continue;
            $status = trim((string)($_POST['status'] ?? '待付款'));
            $paidDate = trim((string)($_POST['paid_date'] ?? ''));
            if ($status === '已付款' && $paidDate === '') $paidDate = date('Y-m-d');
            $payment['due_date'] = trim((string)($_POST['due_date'] ?? ($payment['due_date'] ?? '')));
            $payment['amount'] = max(0, (float)($_POST['amount'] ?? ($payment['amount'] ?? 0)));
            $payment['status'] = $status;
            $payment['paid_date'] = $paidDate;
            $payment['payment_method'] = trim((string)($_POST['payment_method'] ?? ''));
            $payment['account_name'] = trim((string)($_POST['account_name'] ?? ''));
            $payment['invoice_no'] = trim((string)($_POST['invoice_no'] ?? ''));
            $payment['handler'] = trim((string)($_POST['handler'] ?? ''));
            $payment['note'] = trim((string)($_POST['note'] ?? ''));
            $payment['operator'] = current_operator();
            $payment['updated_at'] = date('c');
            $found = true;
            break;
        }
        unset($payment);
        if ($found) {
            write_data('fixed_expense_payments', $fixedExpensePayments);
            $notice = '固定開支付款資料已更新，財報同步完成。';
        } else {
            $notice = '固定開支付款更新失敗：找不到紀錄。';
        }
    }

    if ($action === 'delete_fixed_expense_payment') {
        $id = trim((string)($_POST['fixed_expense_payment_id'] ?? ''));
        $fixedExpensePayments = array_values(array_filter($fixedExpensePayments, function($row) use ($id) { return ($row['id'] ?? '') !== $id; }));
        write_data('fixed_expense_payments', $fixedExpensePayments);
        $notice = '固定開支付款紀錄已刪除。';
    }

    if ($action === 'save_fixed_asset') {
        $id = trim((string)($_POST['fixed_asset_id'] ?? '')) ?: uid('fa_');
        $existingAsset = null;
        foreach ($fixedAssets as $asset) {
            if (($asset['id'] ?? '') === $id) { $existingAsset = $asset; break; }
        }
        $name = trim((string)($_POST['asset_name'] ?? ''));
        $sourceKey = trim((string)($_POST['source_document_key'] ?? ''));
        $sourceType = '';
        $sourceId = '';
        $sourceNo = '';
        $sourceDate = '';
        $sourceSupplierId = '';
        $sourceSupplierName = '';
        $sourceAmount = 0;
        if (strpos($sourceKey, ':') !== false) {
            [$sourceType, $sourceId] = array_pad(explode(':', $sourceKey, 2), 2, '');
        }
        $sourceResolved = false;
        if ($sourceType === 'stock') {
            foreach ($stockMovements as $movement) {
                if (($movement['id'] ?? '') !== $sourceId) continue;
                $sourceNo = $movement['doc_no'] ?? ($movement['id'] ?? '');
                $sourceDate = substr((string)($movement['created_at'] ?? ''), 0, 10);
                $sourceSupplierId = $movement['supplier_id'] ?? '';
                $sourceSupplierName = $movement['supplier_name'] ?? '';
                $sourceAmount = max(0, (float)($movement['qty'] ?? 0) * (float)($movement['unit_cost'] ?? 0));
                if ($name === '') $name = $movement['product_title'] ?? '';
                $sourceResolved = true;
                break;
            }
        } elseif ($sourceType === 'fixed') {
            foreach ($fixedExpensePayments as $payment) {
                if (($payment['id'] ?? '') !== $sourceId) continue;
                $sourceNo = $payment['payment_no'] ?? ($payment['id'] ?? '');
                $paidDate = trim((string)($payment['paid_date'] ?? ''));
                $sourceDate = $paidDate !== '' ? $paidDate : ($payment['due_date'] ?? '');
                $sourceSupplierId = $payment['supplier_id'] ?? '';
                $sourceSupplierName = $payment['supplier_name'] ?? '';
                $sourceAmount = max(0, (float)($payment['amount'] ?? 0));
                if ($name === '') $name = $payment['expense_name'] ?? '';
                $sourceResolved = true;
                break;
            }
        }
        if (!$sourceResolved && $sourceKey !== '' && $existingAsset && ($existingAsset['source_document_key'] ?? '') === $sourceKey) {
            $sourceType = $existingAsset['source_type'] ?? $sourceType;
            $sourceId = $existingAsset['source_id'] ?? $sourceId;
            $sourceNo = $existingAsset['source_no'] ?? '';
            $sourceDate = $existingAsset['acquisition_date'] ?? '';
            $sourceSupplierId = $existingAsset['supplier_id'] ?? '';
            $sourceSupplierName = $existingAsset['supplier_name'] ?? '';
            $sourceAmount = (float)($existingAsset['acquisition_cost'] ?? 0);
        }
        $acquisitionDate = trim((string)($_POST['acquisition_date'] ?? '')) ?: ($sourceDate ?: date('Y-m-d'));
        $acquisitionCost = max(0, (float)($_POST['acquisition_cost'] ?? 0));
        if ($acquisitionCost <= 0) $acquisitionCost = $sourceAmount;
        $supplierId = trim((string)($_POST['supplier_id'] ?? '')) ?: $sourceSupplierId;
        $supplierName = trim((string)($_POST['supplier_name'] ?? '')) ?: $sourceSupplierName;
        foreach ($suppliers as $supplier) {
            if (($supplier['id'] ?? '') === $supplierId) { $supplierName = $supplier['name'] ?? $supplierName; break; }
        }
        $assetNo = trim((string)($_POST['asset_no'] ?? '')) ?: next_fixed_asset_no($fixedAssets, $acquisitionDate);
        $duplicateNo = false;
        foreach ($fixedAssets as $asset) {
            if (($asset['id'] ?? '') !== $id && ($asset['asset_no'] ?? '') === $assetNo) { $duplicateNo = true; break; }
        }
        if ($name === '' || $acquisitionCost <= 0 || $duplicateNo) {
            $notice = $duplicateNo ? '固定資產儲存失敗：資產編號重複。' : '固定資產儲存失敗：請輸入資產名稱與取得成本，或選擇可帶入金額的來源單據。';
        } else {
            $status = trim((string)($_POST['status'] ?? '使用中'));
            $row = [
                'id' => $id,
                'asset_no' => $assetNo,
                'asset_name' => $name,
                'asset_category' => trim((string)($_POST['asset_category'] ?? '其他')),
                'brand_model' => trim((string)($_POST['brand_model'] ?? '')),
                'serial_no' => trim((string)($_POST['serial_no'] ?? '')),
                'quantity' => max(1, (int)($_POST['quantity'] ?? 1)),
                'source_document_key' => $sourceKey,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_no' => $sourceNo,
                'acquisition_date' => $acquisitionDate,
                'acquisition_cost' => $acquisitionCost,
                'supplier_id' => $supplierId,
                'supplier_name' => $supplierName,
                'invoice_no' => trim((string)($_POST['invoice_no'] ?? '')),
                'department' => trim((string)($_POST['department'] ?? '')),
                'location' => trim((string)($_POST['location'] ?? '')),
                'custodian' => trim((string)($_POST['custodian'] ?? '')),
                'status' => $status,
                'depreciation_method' => trim((string)($_POST['depreciation_method'] ?? '直線法')),
                'useful_life_months' => max(1, (int)($_POST['useful_life_months'] ?? 60)),
                'residual_value' => min($acquisitionCost, max(0, (float)($_POST['residual_value'] ?? 0))),
                'depreciation_start_date' => trim((string)($_POST['depreciation_start_date'] ?? '')) ?: $acquisitionDate,
                'warranty_end_date' => trim((string)($_POST['warranty_end_date'] ?? '')),
                'disposed_date' => in_array($status, ['報廢','出售'], true) ? (trim((string)($_POST['disposed_date'] ?? '')) ?: date('Y-m-d')) : '',
                'note' => trim((string)($_POST['note'] ?? '')),
                'operator' => current_operator(),
                'updated_at' => date('c'),
            ];
            $found = false;
            foreach ($fixedAssets as &$asset) {
                if (($asset['id'] ?? '') !== $id) continue;
                $row['created_at'] = $asset['created_at'] ?? date('c');
                $asset = array_merge($asset, $row);
                $found = true;
                break;
            }
            unset($asset);
            if (!$found) {
                $row['created_at'] = date('c');
                $fixedAssets[] = $row;
            }
            write_data('fixed_assets', $fixedAssets);
            $notice = '固定資產已儲存，來源單據、折舊與公司財報已同步。';
        }
    }

    if ($action === 'delete_fixed_asset') {
        $id = trim((string)($_POST['fixed_asset_id'] ?? ''));
        $fixedAssets = array_values(array_filter($fixedAssets, function($row) use ($id) { return ($row['id'] ?? '') !== $id; }));
        write_data('fixed_assets', $fixedAssets);
        $notice = '固定資產資料已刪除。';
    }

    if ($action === 'save_mobile_asset') {
        $id = trim((string)($_POST['mobile_asset_id'] ?? '')) ?: uid('ma_');
        $mobileAssetNo = trim((string)($_POST['mobile_asset_no'] ?? '')) ?: next_mobile_asset_no($mobileAssets, date('Y-m-d'));
        $fixedAssetId = trim((string)($_POST['fixed_asset_id'] ?? ''));
        $fixedAsset = null;
        foreach ($fixedAssets as $asset) {
            if (($asset['id'] ?? '') === $fixedAssetId) { $fixedAsset = $asset; break; }
        }
        $duplicateFixedAsset = false;
        $duplicateMobileNo = false;
        foreach ($mobileAssets as $asset) {
            if (($asset['id'] ?? '') !== $id && ($asset['fixed_asset_id'] ?? '') === $fixedAssetId && $fixedAssetId !== '') $duplicateFixedAsset = true;
            if (($asset['id'] ?? '') !== $id && ($asset['mobile_asset_no'] ?? '') === $mobileAssetNo) $duplicateMobileNo = true;
        }
        if (!$fixedAsset || $duplicateFixedAsset || $duplicateMobileNo) {
            if ($duplicateMobileNo) $notice = '移動資產建立失敗：移動資產編號重複。';
            elseif ($duplicateFixedAsset) $notice = '移動資產建立失敗：此固定資產已經建立移動資產卡。';
            else $notice = '移動資產建立失敗：請選擇固定資產。';
        } else {
            $row = [
                'id' => $id,
                'mobile_asset_no' => $mobileAssetNo,
                'fixed_asset_id' => $fixedAssetId,
                'fixed_asset_no' => $fixedAsset['asset_no'] ?? '',
                'asset_name' => $fixedAsset['asset_name'] ?? '',
                'asset_category' => $fixedAsset['asset_category'] ?? '',
                'brand_model' => $fixedAsset['brand_model'] ?? '',
                'serial_no' => $fixedAsset['serial_no'] ?? '',
                'asset_tag' => trim((string)($_POST['asset_tag'] ?? '')),
                'department' => trim((string)($_POST['department'] ?? '')) ?: ($fixedAsset['department'] ?? ''),
                'home_location' => trim((string)($_POST['home_location'] ?? '')) ?: ($fixedAsset['location'] ?? ''),
                'current_location' => trim((string)($_POST['current_location'] ?? '')) ?: ($fixedAsset['location'] ?? ''),
                'current_holder' => trim((string)($_POST['current_holder'] ?? '')) ?: ($fixedAsset['custodian'] ?? ''),
                'status' => trim((string)($_POST['status'] ?? '可使用')),
                'borrowed_at' => trim((string)($_POST['borrowed_at'] ?? '')),
                'expected_return_date' => trim((string)($_POST['expected_return_date'] ?? '')),
                'note' => trim((string)($_POST['note'] ?? '')),
                'operator' => current_operator(),
                'updated_at' => date('c'),
            ];
            $found = false;
            foreach ($mobileAssets as &$mobileAsset) {
                if (($mobileAsset['id'] ?? '') !== $id) continue;
                $row['created_at'] = $mobileAsset['created_at'] ?? date('c');
                $mobileAsset = array_merge($mobileAsset, $row);
                $found = true;
                break;
            }
            unset($mobileAsset);
            if (!$found) {
                $row['created_at'] = date('c');
                $mobileAssets[] = $row;
            }
            foreach ($fixedAssets as &$asset) {
                if (($asset['id'] ?? '') !== $fixedAssetId) continue;
                $asset['location'] = $row['current_location'];
                $asset['custodian'] = $row['current_holder'];
                if ($row['status'] === '維修中') $asset['status'] = '維修中';
                elseif ($row['status'] === '報廢') { $asset['status'] = '報廢'; $asset['disposed_date'] = trim((string)($asset['disposed_date'] ?? '')) ?: date('Y-m-d'); }
                elseif ($row['status'] === '遺失') $asset['status'] = '遺失';
                else $asset['status'] = '使用中';
                $asset['updated_at'] = date('c');
                break;
            }
            unset($asset);
            write_data('mobile_assets', $mobileAssets);
            write_data('fixed_assets', $fixedAssets);
            $notice = '移動資產卡已儲存，固定資產位置與保管資料已同步。';
        }
    }

    if ($action === 'delete_mobile_asset') {
        $id = trim((string)($_POST['mobile_asset_id'] ?? ''));
        $mobileAssets = array_values(array_filter($mobileAssets, function($row) use ($id) { return ($row['id'] ?? '') !== $id; }));
        write_data('mobile_assets', $mobileAssets);
        $notice = '移動資產卡已刪除；歷史異動紀錄已保留。';
    }

    if ($action === 'save_mobile_asset_movement') {
        $mobileAssetId = trim((string)($_POST['mobile_asset_id'] ?? ''));
        $assetIndex = -1;
        foreach ($mobileAssets as $index => $asset) {
            if (($asset['id'] ?? '') === $mobileAssetId) { $assetIndex = $index; break; }
        }
        if ($assetIndex < 0) {
            $notice = '移動資產異動失敗：找不到資產卡。';
        } else {
            $actionType = trim((string)($_POST['movement_type'] ?? '領用'));
            $actionDate = trim((string)($_POST['movement_date'] ?? date('Y-m-d'))) ?: date('Y-m-d');
            $before = $mobileAssets[$assetIndex];
            $toLocation = trim((string)($_POST['to_location'] ?? ''));
            $toHolder = trim((string)($_POST['to_holder'] ?? ''));
            $statusMap = ['領用'=>'使用中','借出'=>'借出中','歸還'=>'可使用','調撥'=>'可使用','送修'=>'維修中','修復'=>'可使用','遺失'=>'遺失','報廢'=>'報廢'];
            $newStatus = $statusMap[$actionType] ?? ($before['status'] ?? '可使用');
            if ($actionType === '調撥') $newStatus = $before['status'] ?? '可使用';
            if ($actionType === '歸還') {
                $toHolder = '';
                if ($toLocation === '') $toLocation = $before['home_location'] ?? '';
            }
            if ($actionType === '修復' && $toLocation === '') $toLocation = $before['home_location'] ?? ($before['current_location'] ?? '');
            if ($toLocation === '') $toLocation = $before['current_location'] ?? '';
            if (!in_array($actionType, ['歸還','修復'], true) && $toHolder === '') $toHolder = $before['current_holder'] ?? '';
            $expectedReturn = trim((string)($_POST['expected_return_date'] ?? ''));
            $movement = [
                'id' => uid('mam_'),
                'movement_no' => next_mobile_asset_movement_no($mobileAssetMovements, $actionDate),
                'mobile_asset_id' => $mobileAssetId,
                'mobile_asset_no' => $before['mobile_asset_no'] ?? '',
                'fixed_asset_id' => $before['fixed_asset_id'] ?? '',
                'fixed_asset_no' => $before['fixed_asset_no'] ?? '',
                'asset_name' => $before['asset_name'] ?? '',
                'movement_type' => $actionType,
                'movement_date' => $actionDate,
                'from_location' => $before['current_location'] ?? '',
                'to_location' => $toLocation,
                'from_holder' => $before['current_holder'] ?? '',
                'to_holder' => $toHolder,
                'expected_return_date' => $expectedReturn,
                'status_after' => $newStatus,
                'note' => trim((string)($_POST['note'] ?? '')),
                'operator' => current_operator(),
                'created_at' => date('c'),
            ];
            $mobileAssetMovements[] = $movement;
            $mobileAssets[$assetIndex]['current_location'] = $toLocation;
            $mobileAssets[$assetIndex]['current_holder'] = $toHolder;
            $mobileAssets[$assetIndex]['status'] = $newStatus;
            $mobileAssets[$assetIndex]['borrowed_at'] = in_array($actionType, ['借出','領用'], true) ? $actionDate : (($actionType === '歸還') ? '' : ($before['borrowed_at'] ?? ''));
            $mobileAssets[$assetIndex]['expected_return_date'] = in_array($actionType, ['借出','領用'], true) ? $expectedReturn : (($actionType === '歸還') ? '' : ($before['expected_return_date'] ?? ''));
            $mobileAssets[$assetIndex]['last_movement_no'] = $movement['movement_no'];
            $mobileAssets[$assetIndex]['updated_at'] = date('c');
            $linkedFixedAssetId = $before['fixed_asset_id'] ?? '';
            foreach ($fixedAssets as &$asset) {
                if (($asset['id'] ?? '') !== $linkedFixedAssetId) continue;
                $asset['location'] = $toLocation;
                $asset['custodian'] = $toHolder;
                if ($newStatus === '維修中') $asset['status'] = '維修中';
                elseif ($newStatus === '報廢') { $asset['status'] = '報廢'; $asset['disposed_date'] = $actionDate; }
                elseif ($newStatus === '遺失') $asset['status'] = '遺失';
                else $asset['status'] = '使用中';
                $asset['updated_at'] = date('c');
                break;
            }
            unset($asset);
            write_data('mobile_asset_movements', $mobileAssetMovements);
            write_data('mobile_assets', $mobileAssets);
            write_data('fixed_assets', $fixedAssets);
            $notice = '移動資產異動已建立：' . $movement['movement_no'] . '，固定資產資料已同步。';
        }
    }

    if ($action === 'save_collection_receipt') {
        $id = trim($_POST['receipt_id'] ?? '') ?: uid('cr_');
        $selectedDocumentNos = $_POST['document_nos'] ?? [];
        if (!is_array($selectedDocumentNos)) $selectedDocumentNos = [];
        if (!$selectedDocumentNos) $selectedDocumentNos = preg_split('/\s*[,、]\s*/u', trim((string)($_POST['document_no'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
        $selectedDocumentNos = array_values(array_unique(array_filter(array_map('trim', $selectedDocumentNos))));
        $receiptAmount = max(0, (float)($_POST['amount'] ?? 0));
        $remainingReceiptAmount = $receiptAmount;
        $receiptAllocations = [];
        foreach ($selectedDocumentNos as $documentNo) {
            $matchedSchedule = null;
            foreach ($schedules as $schedule) {
                if (($schedule['id'] ?? '') === $documentNo || ($schedule['order_no'] ?? '') === $documentNo) { $matchedSchedule = $schedule; break; }
            }
            if (!$matchedSchedule || $remainingReceiptAmount <= 0) continue;
            $product = product_by_id($products, $matchedSchedule['product_id'] ?? '');
            $scheduleTotals = totals($matchedSchedule, $product);
            $otherReceiptPaid = 0;
            foreach ($collectionReceipts as $existingReceipt) {
                if (($existingReceipt['id'] ?? '') === $id || !in_array(($existingReceipt['status'] ?? ''), ['已確認','部分收款'], true)) continue;
                foreach (($existingReceipt['allocations'] ?? []) as $allocation) {
                    if (($allocation['schedule_id'] ?? '') === ($matchedSchedule['id'] ?? '')) $otherReceiptPaid += max(0, (float)($allocation['amount'] ?? 0));
                }
            }
            $existingPaid = max((float)($matchedSchedule['paid_amount'] ?? 0), $otherReceiptPaid);
            $outstanding = max(0, (float)$scheduleTotals['receivable'] - $existingPaid);
            $allocated = min($remainingReceiptAmount, $outstanding);
            if ($allocated <= 0) continue;
            $receiptAllocations[] = [
                'schedule_id' => $matchedSchedule['id'] ?? '',
                'document_no' => $matchedSchedule['order_no'] ?? ($matchedSchedule['id'] ?? ''),
                'amount' => $allocated,
            ];
            $remainingReceiptAmount -= $allocated;
        }
        $row = [
            'id' => $id,
            'receipt_no' => trim($_POST['receipt_no'] ?? '') ?: ('CR-' . date('Ymd-His')),
            'receipt_date' => trim($_POST['receipt_date'] ?? date('Y-m-d')),
            'customer_name' => trim($_POST['customer_name'] ?? ''),
            'document_no' => implode('、', $selectedDocumentNos),
            'document_nos' => $selectedDocumentNos,
            'payment_method' => trim($_POST['payment_method'] ?? ''),
            'account_name' => trim($_POST['account_name'] ?? ''),
            'amount' => $receiptAmount,
            'allocations' => $receiptAllocations,
            'unallocated_amount' => $remainingReceiptAmount,
            'status' => trim($_POST['status'] ?? '待確認'),
            'handler' => trim($_POST['handler'] ?? ''),
            'note' => trim($_POST['note'] ?? ''),
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        $found = false;
        foreach ($collectionReceipts as &$receipt) {
            if (($receipt['id'] ?? '') === $id) {
                $row['created_at'] = $receipt['created_at'] ?? $row['created_at'];
                $receipt = array_merge($receipt, $row);
                $found = true;
                break;
            }
        }
        unset($receipt);
        if (!$found) $collectionReceipts[] = $row;
        usort($collectionReceipts, function($a, $b) { return strcmp((string)($b['receipt_date'] ?? ''), (string)($a['receipt_date'] ?? '')); });
        write_data('collection_receipts', $collectionReceipts);
        $notice = '收款單已儲存。';
    }

    if ($action === 'delete_collection_receipt') {
        $id = trim($_POST['receipt_id'] ?? '');
        $collectionReceipts = array_values(array_filter($collectionReceipts, function($r) use ($id) { return ($r['id'] ?? '') !== $id; }));
        write_data('collection_receipts', $collectionReceipts);
        $notice = '收款單已刪除。';
    }

    if ($action === 'delete_collection_receipts') {
        $ids = $_POST['receipt_ids'] ?? [];
        $collectionReceipts = array_values(array_filter($collectionReceipts, function($r) use ($ids) { return !in_array($r['id'] ?? '', $ids, true); }));
        write_data('collection_receipts', $collectionReceipts);
        $notice = '已刪除勾選收款單。';
    }

    if ($action === 'save_billing_request') {
        $selectedScheduleIds = $_POST['billing_schedule_ids'] ?? [];
        if (!is_array($selectedScheduleIds)) $selectedScheduleIds = [];
        $selectedScheduleIds = array_values(array_unique(array_filter(array_map('trim', $selectedScheduleIds))));
        $customerName = trim((string)($_POST['billing_customer_name'] ?? ''));
        $items = [];
        foreach ($schedules as $schedule) {
            if (!in_array(($schedule['id'] ?? ''), $selectedScheduleIds, true)) continue;
            $scheduleCustomer = trim((string)($schedule['winner'] ?? ''));
            if ($customerName === '' || $scheduleCustomer !== $customerName) continue;
            $product = product_by_id($products, $schedule['product_id'] ?? '');
            $scheduleTotals = totals($schedule, $product);
            $receivable = (float)$scheduleTotals['receivable'];
            $schedulePaid = (float)($schedule['paid_amount'] ?? 0);
            $collectionPaid = 0;
            foreach ($collectionReceipts as $receipt) {
                if (!in_array(($receipt['status'] ?? ''), ['已確認','部分收款'], true)) continue;
                foreach (($receipt['allocations'] ?? []) as $allocation) {
                    if (($allocation['schedule_id'] ?? '') === ($schedule['id'] ?? '')) $collectionPaid += max(0, (float)($allocation['amount'] ?? 0));
                }
            }
            $paid = max($schedulePaid, $collectionPaid);
            $outstanding = max(0, $receivable - $paid);
            if ($outstanding <= 0) continue;
            $items[] = [
                'schedule_id' => $schedule['id'] ?? '',
                'document_no' => $schedule['order_no'] ?? ($schedule['id'] ?? ''),
                'date' => substr((string)($schedule['close_at'] ?? $schedule['publish_at'] ?? $schedule['created_at'] ?? ''), 0, 10),
                'product_id' => $schedule['product_id'] ?? '',
                'product_title' => $schedule['product_title'] ?? ($product['title'] ?? ''),
                'quantity' => max(1, (int)($schedule['quantity'] ?? 1)),
                'receivable' => $receivable,
                'paid' => $paid,
                'request_amount' => $outstanding,
                'delivery_no' => '',
                'invoice_no' => trim((string)($schedule['invoice_no'] ?? '')),
            ];
        }
        usort($items, function($a, $b) { return strcmp(($a['date'] ?? '') . ($a['document_no'] ?? ''), ($b['date'] ?? '') . ($b['document_no'] ?? '')); });
        if ($customerName === '' || !$items) {
            $notice = '請款單建立失敗：請選擇客戶並勾選至少一筆尚未結清的單據。';
        } else {
            foreach ($deliveryNotes as $delivery) {
                foreach ($items as &$item) {
                    if (in_array($item['schedule_id'], $delivery['schedule_ids'] ?? [], true)) $item['delivery_no'] = $delivery['delivery_no'] ?? '';
                }
                unset($item);
            }
            $billingRequests[] = [
                'id' => uid('ar_'),
                'request_no' => next_billing_request_no($billingRequests),
                'request_date' => trim((string)($_POST['billing_request_date'] ?? date('Y-m-d'))),
                'due_date' => trim((string)($_POST['billing_due_date'] ?? '')),
                'customer_name' => $customerName,
                'items' => $items,
                'total_amount' => array_sum(array_column($items, 'request_amount')),
                'status' => trim((string)($_POST['billing_status'] ?? '待請款')),
                'note' => trim((string)($_POST['billing_note'] ?? '')),
                'operator' => current_operator(),
                'created_at' => date('c'),
            ];
            write_data('billing_requests', $billingRequests);
            $notice = '請款單已建立：' . end($billingRequests)['request_no'] . '。';
        }
    }

    if ($action === 'delete_billing_request') {
        $billingRequestId = trim((string)($_POST['billing_request_id'] ?? ''));
        $billingRequests = array_values(array_filter($billingRequests, function($request) use ($billingRequestId) { return ($request['id'] ?? '') !== $billingRequestId; }));
        write_data('billing_requests', $billingRequests);
        $notice = '請款單已刪除。';
    }

    if ($action === 'save_bad_debt') {
        $selectedScheduleIds = $_POST['bad_debt_schedule_ids'] ?? [];
        if (!is_array($selectedScheduleIds)) $selectedScheduleIds = [];
        $selectedScheduleIds = array_values(array_unique(array_filter(array_map('trim', $selectedScheduleIds))));
        $customerName = trim((string)($_POST['bad_debt_customer_name'] ?? ''));
        $activeScheduleIds = [];
        foreach ($badDebts as $case) {
            if (in_array(($case['status'] ?? ''), ['已追回','已核准沖銷','取消'], true)) continue;
            foreach (($case['items'] ?? []) as $item) $activeScheduleIds[$item['schedule_id'] ?? ''] = true;
        }
        $items = [];
        foreach ($schedules as $schedule) {
            $scheduleId = trim((string)($schedule['id'] ?? ''));
            if (!in_array($scheduleId, $selectedScheduleIds, true) || isset($activeScheduleIds[$scheduleId])) continue;
            $scheduleCustomer = trim((string)($schedule['winner'] ?? ''));
            if ($scheduleCustomer === '') $scheduleCustomer = trim((string)($schedule['winner_facebook'] ?? ''));
            if ($scheduleCustomer === '') $scheduleCustomer = '未指定客戶';
            if ($scheduleCustomer !== $customerName) continue;
            $product = product_by_id($products, $schedule['product_id'] ?? '');
            $scheduleTotals = totals($schedule, $product);
            $orderNo = trim((string)($schedule['order_no'] ?? $scheduleId));
            $receivable = (float)$scheduleTotals['receivable'];
            $paid = max((float)($schedule['paid_amount'] ?? 0), confirmed_receipt_paid_for_schedule($collectionReceipts, $scheduleId, $orderNo));
            $outstanding = max(0, $receivable - $paid);
            if ($outstanding <= 0) continue;
            $items[] = [
                'schedule_id' => $scheduleId,
                'document_no' => $orderNo,
                'date' => substr((string)($schedule['close_at'] ?? $schedule['publish_at'] ?? $schedule['created_at'] ?? ''), 0, 10),
                'product_id' => $schedule['product_id'] ?? '',
                'product_title' => $schedule['product_title'] ?? ($product['title'] ?? ''),
                'quantity' => max(1, (int)($schedule['quantity'] ?? 1)),
                'receivable' => $receivable,
                'paid' => $paid,
                'bad_debt_amount' => $outstanding,
            ];
        }
        usort($items, function($a, $b) { return strcmp(($a['date'] ?? '') . ($a['document_no'] ?? ''), ($b['date'] ?? '') . ($b['document_no'] ?? '')); });
        if ($customerName === '' || !$items) {
            $notice = '呆帳建立失敗：請選擇客戶及尚未被追蹤的未結單據。';
        } else {
            $amount = array_sum(array_column($items, 'bad_debt_amount'));
            $badDebts[] = [
                'id' => uid('bd_'),
                'case_no' => next_bad_debt_no($badDebts),
                'recognition_date' => trim((string)($_POST['bad_debt_recognition_date'] ?? date('Y-m-d'))),
                'original_due_date' => trim((string)($_POST['bad_debt_due_date'] ?? '')),
                'customer_name' => $customerName,
                'customer_phone' => trim((string)($_POST['bad_debt_customer_phone'] ?? '')),
                'reason' => trim((string)($_POST['bad_debt_reason'] ?? '逾期未付款')),
                'amount' => $amount,
                'recovered_amount' => 0,
                'remaining_amount' => $amount,
                'status' => '待催收',
                'owner' => trim((string)($_POST['bad_debt_owner'] ?? current_operator())),
                'next_followup_at' => trim((string)($_POST['bad_debt_next_followup_at'] ?? '')),
                'note' => trim((string)($_POST['bad_debt_note'] ?? '')),
                'items' => $items,
                'followups' => [],
                'operator' => current_operator(),
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];
            write_data('bad_debts', $badDebts);
            $newCase = end($badDebts);
            $notice = '呆帳案件已建立：' . ($newCase['case_no'] ?? '') . '。';
        }
    }

    if ($action === 'update_bad_debt') {
        $caseId = trim((string)($_POST['bad_debt_id'] ?? ''));
        $requestedStatus = trim((string)($_POST['bad_debt_status'] ?? '催收中'));
        $writeoffReason = trim((string)($_POST['bad_debt_writeoff_reason'] ?? ''));
        $approvalBy = trim((string)($_POST['bad_debt_approval_by'] ?? ''));
        if ($requestedStatus === '已核准沖銷' && ($writeoffReason === '' || $approvalBy === '')) {
            $notice = '呆帳更新失敗：核准沖銷必須填寫沖銷原因與核准人。';
        } else {
            $updated = false;
            $recoveryReceipt = null;
            foreach ($badDebts as &$case) {
                if (($case['id'] ?? '') !== $caseId) continue;
                $requestedIncrement = max(0, (float)($_POST['bad_debt_recovered_increment'] ?? 0));
                $actualIncrement = min($requestedIncrement, max(0, (float)($case['amount'] ?? 0) - (float)($case['recovered_amount'] ?? 0)));
                $case['recovered_amount'] = (float)($case['recovered_amount'] ?? 0) + $actualIncrement;
                $case['remaining_amount'] = max(0, (float)($case['amount'] ?? 0) - (float)$case['recovered_amount']);
                $case['status'] = $case['remaining_amount'] <= 0 ? '已追回' : ($actualIncrement > 0 && $requestedStatus === '催收中' ? '部分追回' : $requestedStatus);
                $case['owner'] = trim((string)($_POST['bad_debt_owner'] ?? ($case['owner'] ?? '')));
                $case['next_followup_at'] = trim((string)($_POST['bad_debt_next_followup_at'] ?? ''));
                $case['writeoff_reason'] = $writeoffReason;
                $case['approval_by'] = $approvalBy;
                if ($case['status'] === '已核准沖銷') $case['writeoff_date'] = trim((string)($_POST['bad_debt_writeoff_date'] ?? date('Y-m-d')));
                $contactDate = trim((string)($_POST['bad_debt_contact_date'] ?? date('Y-m-d')));
                $method = trim((string)($_POST['bad_debt_collection_method'] ?? '電話'));
                $result = trim((string)($_POST['bad_debt_followup_result'] ?? ''));
                if ($result !== '' || $actualIncrement > 0) {
                    if (!isset($case['followups']) || !is_array($case['followups'])) $case['followups'] = [];
                    $case['followups'][] = ['id'=>uid('bdf_'),'date'=>$contactDate,'method'=>$method,'result'=>$result,'recovered_amount'=>$actualIncrement,'next_followup_at'=>$case['next_followup_at'],'operator'=>current_operator(),'created_at'=>date('c')];
                }
                if ($actualIncrement > 0) {
                    $remainingRecovery = $actualIncrement;
                    $allocations = [];
                    $documentNos = [];
                    foreach (($case['items'] ?? []) as $caseItem) {
                        if ($remainingRecovery <= 0) break;
                        $scheduleId = trim((string)($caseItem['schedule_id'] ?? ''));
                        $orderNo = trim((string)($caseItem['document_no'] ?? $scheduleId));
                        $matchedSchedule = null;
                        foreach ($schedules as $schedule) if (($schedule['id'] ?? '') === $scheduleId) { $matchedSchedule = $schedule; break; }
                        if (!$matchedSchedule) continue;
                        $product = product_by_id($products, $matchedSchedule['product_id'] ?? '');
                        $scheduleTotals = totals($matchedSchedule, $product);
                        $currentPaid = max((float)($matchedSchedule['paid_amount'] ?? 0), confirmed_receipt_paid_for_schedule($collectionReceipts, $scheduleId, $orderNo));
                        $currentOutstanding = max(0, (float)$scheduleTotals['receivable'] - $currentPaid);
                        $allocated = min($remainingRecovery, $currentOutstanding);
                        if ($allocated <= 0) continue;
                        $allocations[] = ['schedule_id'=>$scheduleId,'document_no'=>$orderNo,'amount'=>$allocated];
                        $documentNos[] = $scheduleId;
                        $remainingRecovery -= $allocated;
                    }
                    $recoveryReceipt = [
                        'id' => uid('cr_bd_'),
                        'receipt_no' => 'CR-BD-' . date('Ymd-His'),
                        'receipt_date' => $contactDate,
                        'customer_name' => $case['customer_name'] ?? '',
                        'document_no' => implode('、', $documentNos),
                        'document_nos' => $documentNos,
                        'payment_method' => trim((string)($_POST['bad_debt_payment_method'] ?? '匯款')),
                        'account_name' => trim((string)($_POST['bad_debt_account_name'] ?? '')),
                        'amount' => $actualIncrement,
                        'allocations' => $allocations,
                        'unallocated_amount' => $remainingRecovery,
                        'status' => '已確認',
                        'handler' => current_operator(),
                        'note' => '呆帳追回：' . ($case['case_no'] ?? '') . ($result !== '' ? ' / ' . $result : ''),
                        'created_at' => date('c'),
                        'updated_at' => date('c'),
                    ];
                }
                $case['updated_at'] = date('c');
                $updated = true;
                break;
            }
            unset($case);
            if ($updated) {
                write_data('bad_debts', $badDebts);
                if ($recoveryReceipt) {
                    $collectionReceipts[] = $recoveryReceipt;
                    write_data('collection_receipts', $collectionReceipts);
                }
                $notice = '呆帳追蹤紀錄已更新。';
            } else {
                $notice = '呆帳更新失敗：找不到案件。';
            }
        }
    }

    if ($action === 'delete_bad_debt') {
        $caseId = trim((string)($_POST['bad_debt_id'] ?? ''));
        $badDebts = array_values(array_filter($badDebts, function($case) use ($caseId) { return ($case['id'] ?? '') !== $caseId; }));
        write_data('bad_debts', $badDebts);
        $notice = '呆帳案件已刪除。';
    }

    if ($action === 'delete_schedules') {
        $ids = $_POST['schedule_ids'] ?? [];
        $kept = [];
        foreach ($schedules as $s) {
            if (in_array($s['id'] ?? '', $ids, true)) {
                foreach ($products as &$p) if (($p['id'] ?? '') === ($s['product_id'] ?? '')) $p['stock_reserved'] = max(0, (int)($p['stock_reserved'] ?? 0) - (int)($s['quantity'] ?? 1));
                unset($p);
            } else $kept[] = $s;
        }
        $schedules = $kept;
        $members = rebuild_member_stats($members, $schedules, $products);
        write_data('products', $products);
        write_data('schedules', $schedules);
        write_data('members', $members);
        $notice = '已刪除選取排程，並扣回預約庫存。';
    }

    if ($action === 'apply_product_category_tree') {
        $treeNotice = product_category_tree_migrate($productCategories, $products, $marketplaceCategoryMappings, true);
        $opsInitialTab = 'product-categories';
        $notice = '已依新分類樹分辨並搬移商品：組裝硬體／男性專區／女性專區／生活周邊，共 ' . (int)$treeNotice['moved_products'] . ' 件。倉庫部門沒有改。';
    }
}

$products = read_data('products');
$schedules = read_data('schedules');
$cloudInventoryReport = read_json_object('cloud_inventory_reconciliation');
$cloudInventorySummary = is_array($cloudInventoryReport['summary'] ?? null) ? $cloudInventoryReport['summary'] : [];
$cloudInventoryRows = is_array($cloudInventoryReport['rows'] ?? null) ? $cloudInventoryReport['rows'] : [];
$cloudInventoryLockedRows = array_values(array_filter($cloudInventoryRows, function($row) {
    return !empty($row['product_id']) && (int)($row['cloud_reserved'] ?? 0) > 0;
}));
$cloudInventoryIssueRows = array_values(array_filter($cloudInventoryRows, function($row) {
    return empty($row['product_id']) || empty($row['balance_valid']);
}));
$productCategories = read_data('product_categories');
$marketplaceCategoryMappings = read_data('marketplace_category_mappings');
if (product_category_tree_needed()) {
    $treeNotice = product_category_tree_migrate($productCategories, $products, $marketplaceCategoryMappings, true);
    if ($notice === '') {
        $notice = '已套用新分類樹：組裝硬體／男性專區／女性專區／生活周邊，搬移 ' . (int)$treeNotice['moved_products'] . ' 件商品。倉別部門沒有改。';
        $opsInitialTab = 'product-categories';
    }
}
$deliveryNotes = read_data('delivery_notes');
$shipFilterQ = trim((string)($_GET['ship_q'] ?? ''));
$shipFilterCustomer = trim((string)($_GET['ship_customer'] ?? ''));
$shipFilterPhone = trim((string)($_GET['ship_phone'] ?? ''));
$shipFilterNo = trim((string)($_GET['ship_no'] ?? ''));
$shipFilterStatus = trim((string)($_GET['ship_status'] ?? ''));
$shipFilterFrom = trim((string)($_GET['ship_from'] ?? ''));
$shipFilterTo = trim((string)($_GET['ship_to'] ?? ''));
$editDeliveryId = trim((string)($_GET['edit_delivery'] ?? $salesOutKeepEditId));
$editingDelivery = $editDeliveryId !== '' ? find_delivery_note($deliveryNotes, $editDeliveryId) : null;
if ($editingDelivery || $shipFilterQ !== '' || $shipFilterCustomer !== '' || $shipFilterPhone !== '' || $shipFilterNo !== '' || $shipFilterStatus !== '' || $shipFilterFrom !== '' || $shipFilterTo !== '') {
    $opsInitialTab = 'customer-shipping';
}
$filteredDeliveryNotes = array_values(array_filter($deliveryNotes, function($dn) use ($shipFilterQ, $shipFilterCustomer, $shipFilterPhone, $shipFilterNo, $shipFilterStatus, $shipFilterFrom, $shipFilterTo) {
    if (!is_array($dn)) return false;
    $buyer = is_array($dn['buyer'] ?? null) ? $dn['buyer'] : [];
    $name = (string)($buyer['name'] ?? '');
    $phone = (string)($buyer['phone'] ?? '');
    $address = (string)($buyer['address'] ?? '');
    $no = (string)($dn['delivery_no'] ?? '');
    $status = (string)($dn['status'] ?? ($dn['shipping_status'] ?? ''));
    $date = (string)($dn['date'] ?? substr((string)($dn['created_at'] ?? ''), 0, 10));
    $blob = $no . ' ' . $name . ' ' . $phone . ' ' . $address . ' ' . (string)($dn['tracking_no'] ?? '') . ' ' . (string)($dn['invoice_no'] ?? '') . ' ' . (string)($dn['note'] ?? '');
    foreach (($dn['items'] ?? []) as $it) {
        if (is_array($it)) $blob .= ' ' . (string)($it['product_title'] ?? '') . ' ' . (string)($it['product_id'] ?? '');
    }
    if ($shipFilterNo !== '' && mb_stripos($no, $shipFilterNo) === false) return false;
    if ($shipFilterCustomer !== '' && mb_stripos($name, $shipFilterCustomer) === false) return false;
    if ($shipFilterPhone !== '' && mb_stripos($phone, $shipFilterPhone) === false) return false;
    if ($shipFilterStatus !== '' && $status !== $shipFilterStatus) return false;
    if ($shipFilterFrom !== '' && $date !== '' && $date < $shipFilterFrom) return false;
    if ($shipFilterTo !== '' && $date !== '' && $date > $shipFilterTo) return false;
    if ($shipFilterQ !== '' && mb_stripos($blob, $shipFilterQ) === false) return false;
    return true;
}));
$memberSourceRows = read_data('members');
$memberRecoveryUsed = false;
if (!$memberSourceRows) {
    $memberSourceRows = read_data('members.gjp.latest');
    $memberRecoveryUsed = !empty($memberSourceRows);
}
$members = rebuild_member_stats($memberSourceRows, $schedules, $products);
$normalizeChanged = false; // one-dollar-normalize-buyer-reminder-20260703
foreach ($schedules as &$s) {
    if (trim((string)($s['order_token'] ?? '')) === '') { $s['order_token'] = bin2hex(random_bytes(16)); $normalizeChanged = true; }
    if (!array_key_exists('show_on_buyer_board', $s)) { $s['show_on_buyer_board'] = '1'; $normalizeChanged = true; }
    if (!array_key_exists('current_bid', $s)) { $s['current_bid'] = (float)($s['winning_price'] ?? 0); $normalizeChanged = true; }
    if (!array_key_exists('bid_updated_at', $s)) { $s['bid_updated_at'] = ''; $normalizeChanged = true; }
    if (!array_key_exists('last_reminder_at', $s)) { $s['last_reminder_at'] = ''; $normalizeChanged = true; }
    if (!array_key_exists('next_reminder_at', $s)) { $s['next_reminder_at'] = reminder_next_time($s); $normalizeChanged = true; }
    if (!array_key_exists('reminder_status', $s)) { $s['reminder_status'] = schedule_needs_hourly_update($s) ? '需要更新' : '待確認發布'; $normalizeChanged = true; }
    if (!array_key_exists('reminder_draft', $s)) { $s['reminder_draft'] = ''; $normalizeChanged = true; }
    if (!array_key_exists('fb_qa_pinned', $s)) { $s['fb_qa_pinned'] = '0'; $normalizeChanged = true; }
    if (!array_key_exists('fb_winner_notice_sent', $s)) { $s['fb_winner_notice_sent'] = '0'; $normalizeChanged = true; }
}
unset($s);
if ($normalizeChanged) write_data('schedules', $schedules);

$returns = read_data('returns');
$repairDocuments = read_data('repair_documents');
$suppliers = read_data('suppliers');
$defaultStockSupplierId = ensure_pinduoduo_taobao_supplier($suppliers);
$stockMovements = read_data('stock_movements');
$paymentRecords = read_data('payment_records');
$warehouses = read_data('warehouses');
$financeExpenseCategories = read_data('finance_expense_categories');
$fixedExpenses = read_data('fixed_expenses');
$fixedExpensePayments = read_data('fixed_expense_payments');
$fixedAssets = read_data('fixed_assets');
$mobileAssets = read_data('mobile_assets');
$mobileAssetMovements = read_data('mobile_asset_movements');
[$fixedExpensePayments, $fixedExpenseAutoCreated] = generate_fixed_expense_payments($fixedExpenses, $fixedExpensePayments, date('Y-m'), true);
[$fixedExpensePayments, $fixedExpenseOverdueChanged] = refresh_fixed_expense_overdue($fixedExpensePayments);
if ($fixedExpenseAutoCreated > 0 || $fixedExpenseOverdueChanged) write_data('fixed_expense_payments', $fixedExpensePayments);
if (function_exists('bhm_sync_members_between_systems')) {
    $memberSync = bhm_sync_members_between_systems(null, null, $members);
    $members = $memberSync['auction_members'];
    if (!empty($memberSync['changed_admin'])) bhm_write_admin_data($memberSync['admin_data']);
    if (!empty($memberSync['changed_auction'])) $memberRecoveryUsed = true;
}
if ($memberRecoveryUsed) write_data('members', $members);

$filterPay = $_GET['pay'] ?? '';
$filterShip = $_GET['ship'] ?? '';
$filterOrder = $_GET['order'] ?? '';
$filterClose = $_GET['close_status'] ?? '';
$memberQ = trim($_GET['member_q'] ?? '');
$memberRisk = trim($_GET['member_risk'] ?? '');
$buyerQ = trim($_GET['buyer_q'] ?? '');
$stockQ = trim($_GET['stock_q'] ?? '');
$stockWarehouse = trim($_GET['stock_warehouse'] ?? '');
$stockShelf = trim($_GET['stock_shelf'] ?? '');
$stockLayer = trim($_GET['stock_layer'] ?? '');
$stockCondition = trim((string)($_GET['stock_condition'] ?? ''));
$stockDepartment = trim((string)($_GET['stock_department'] ?? ''));
$stockCategoryGroup = trim((string)($_GET['stock_category_group'] ?? ''));
$stockCategoryType = trim((string)($_GET['stock_category_type'] ?? ''));
$stockCategoryBrand = trim((string)($_GET['stock_category_brand'] ?? ''));
$stockCategorySpec = trim((string)($_GET['stock_category_spec'] ?? ''));
$stockMinAvailable = max(0, (int)($_GET['stock_min_available'] ?? 0));
$stockImageFilter = trim((string)($_GET['stock_image_filter'] ?? ''));
$stockDateFrom = trim((string)($_GET['stock_date_from'] ?? ''));
$stockDateTo = trim((string)($_GET['stock_date_to'] ?? ''));
$stockPage = max(1, (int)($_GET['stock_page'] ?? 1));
$stockLimit = 10;
$departmentWarehouseMap = department_warehouse_map();
$departmentOptions = array_keys($departmentWarehouseMap);
foreach ($products as $p) {
    $dept = trim((string)($p['department'] ?? ''));
    if ($dept !== '') $departmentOptions[] = $dept;
}
foreach ($warehouses as $w) {
    $dept = trim((string)($w['department'] ?? ''));
    if ($dept !== '') $departmentOptions[] = $dept;
}
$departmentOptions = array_values(array_unique(array_filter($departmentOptions)));
sort($departmentOptions, SORT_NATURAL);
$warehouseOptions = [];
foreach ($departmentWarehouseMap as $dept => $names) foreach ($names as $name) $warehouseOptions[] = $name;
$warehouseOptions = array_values(array_unique(array_merge($warehouseOptions, location_values($products, $warehouses, 'warehouse_name', 'warehouse'))));
$warehouseOptions = array_values(array_filter($warehouseOptions, function($v) { return trim((string)$v) !== '' && trim((string)$v) !== '服裝倉'; }));
sort($warehouseOptions, SORT_NATURAL);
$shelfOptions = location_values([], $warehouses, 'shelf_code', 'shelf');
$layerOptions = ['上層', '下層'];
$categoryGroups = unique_values_from_categories($productCategories, 'group');
$categoryGroupOptions = array_values(array_unique(array_filter(array_merge(product_category_root_groups(), $categoryGroups), function($v) {
    return trim((string)$v) !== '' && !in_array(trim((string)$v), ['電腦倉', '台灣倉', '中國倉', '印尼倉', '服裝倉', '電腦部門', '服裝部門', '電腦', '服裝'], true);
})));
$categoryGroupOptions = array_values(array_unique(array_merge(product_category_root_groups(), $categoryGroupOptions)));
$categoryTypes = unique_values_from_categories($productCategories, 'type');
$categoryBrands = unique_values_from_categories($productCategories, 'brand');
$categorySpecs = unique_values_from_categories($productCategories, 'spec');
$productCategoryPaths = [];
foreach ($productCategories as $categoryRow) {
    $group = trim((string)($categoryRow['group'] ?? ''));
    $type = trim((string)($categoryRow['type'] ?? ''));
    if ($group === '' || $type === '') continue;
    $productCategoryPaths[$group . '|' . $type] = ['group' => $group, 'type' => $type];
}
uasort($productCategoryPaths, function($a, $b) {
    $groupCompare = strnatcmp((string)$a['group'], (string)$b['group']);
    return $groupCompare !== 0 ? $groupCompare : strnatcmp((string)$a['type'], (string)$b['type']);
});
$productCategoryRulesForJs = array_map(function($categoryRow) use ($products) {
    $categoryRow['type_code'] = category_type_code($categoryRow['type'] ?? '', $categoryRow['type_code'] ?? '', $categoryRow['barcode_prefix'] ?? '');
    $categoryRow['barcode_prefix'] = normalize_barcode_prefix($categoryRow['barcode_prefix'] ?? '');
    $categoryRow['next_barcode'] = next_product_barcode($products, $categoryRow['barcode_prefix']);
    $categoryRow['next_serial'] = next_type_serial($products, $categoryRow['type_code']);
    return $categoryRow;
}, array_values($productCategories));
$sharedCategoryTypes = array_values(array_unique(array_filter(array_merge(
    array_keys(category_type_code_map()),
    array_map(function($row) { return trim((string)($row['type'] ?? '')); }, $productCategories)
))));
sort($sharedCategoryTypes, SORT_NATURAL);
$stockCategoryRulesForJs = $productCategoryRulesForJs;
$stockCategoryRuleKeys = [];
foreach ($stockCategoryRulesForJs as $rule) {
    $stockCategoryRuleKeys[implode('|', [$rule['group'] ?? '', $rule['type'] ?? '', $rule['brand'] ?? '', $rule['spec'] ?? ''])] = true;
}
foreach ($products as $productRow) {
    $rule = [
        'group' => trim((string)($productRow['category_group'] ?? '')),
        'type' => trim((string)($productRow['category_type'] ?? '')),
        'brand' => trim((string)($productRow['category_brand'] ?? '')),
        'spec' => trim((string)($productRow['category_spec'] ?? '')),
    ];
    if ($rule['group'] === '' || $rule['type'] === '') continue;
    $key = implode('|', $rule);
    if (isset($stockCategoryRuleKeys[$key])) continue;
    $stockCategoryRuleKeys[$key] = true;
    $stockCategoryRulesForJs[] = $rule;
}
$editProductId = trim((string)($_GET['edit_product'] ?? ''));
$editProduct = $editProductId !== '' ? (product_by_id($products, $editProductId) ?: product_by_key($products, $editProductId)) : [];
$isEditingProduct = !empty($editProduct);
if ($isEditingProduct) $opsInitialTab = 'products';
if ($notice === '' && isset($_GET['product_saved'])) {
    $notice = '商品已儲存，可以繼續改名稱、分類、倉位與圖片。';
    $opsInitialTab = 'products';
}
$productListQ = trim((string)($_GET['product_q'] ?? ''));
$productListCondition = trim((string)($_GET['product_condition'] ?? ''));
$productListLimit = 10;
$productListPage = max(1, (int)($_GET['product_page'] ?? 1));
$productListRows = array_values(array_filter($products, function($p) use ($productListQ, $productListCondition, $isEditingProduct, $editProduct) {
    if ($isEditingProduct) return ($p['id'] ?? '') === ($editProduct['id'] ?? '');
    if ($productListCondition !== '' && ($p['product_condition'] ?? '') !== $productListCondition) return false;
    if ($productListQ === '') return true;
    $hay = implode(' ', [$p['id'] ?? '', $p['barcode'] ?? '', $p['title'] ?? '', $p['product_name'] ?? '', $p['department'] ?? '', $p['product_condition'] ?? '', $p['category_group'] ?? '', $p['category_type'] ?? '', $p['category_brand'] ?? '', $p['category_spec'] ?? '', $p['color'] ?? '', $p['color_code'] ?? '', $p['size'] ?? '', $p['size_code'] ?? '', $p['spec'] ?? '']);
    return mb_stripos($hay, $productListQ, 0, 'UTF-8') !== false;
}));
if (!$isEditingProduct) {
    usort($productListRows, function($a, $b) {
        return strcmp(product_barcode_sort_key($a), product_barcode_sort_key($b));
    });
}
$productListTotal = count($productListRows);
$productListPages = max(1, (int)ceil($productListTotal / $productListLimit));
if (!$isEditingProduct) {
    $productListPage = min($productListPage, $productListPages);
    $productListRows = array_slice($productListRows, ($productListPage - 1) * $productListLimit, $productListLimit);
} else {
    $productListPage = 1;
    $productListPages = 1;
}
$stockFiltered = array_values(array_filter($products, function($p) use ($stockQ, $stockWarehouse, $stockShelf, $stockLayer, $stockCondition, $stockDepartment, $stockCategoryGroup, $stockCategoryType, $stockCategoryBrand, $stockCategorySpec, $stockMinAvailable, $stockImageFilter, $stockDateFrom, $stockDateTo) {
    $hay = implode(' ', [$p['id'] ?? '', $p['barcode'] ?? '', $p['title'] ?? '', $p['product_name'] ?? '', $p['department'] ?? '', $p['product_condition'] ?? '', $p['category_group'] ?? '', $p['category_type'] ?? '', $p['category_brand'] ?? '', $p['category_spec'] ?? '', $p['color'] ?? '', $p['color_code'] ?? '', $p['size'] ?? '', $p['size_code'] ?? '', $p['spec'] ?? '', $p['warehouse_name'] ?? '', $p['shelf_code'] ?? '', $p['warehouse_location'] ?? '']);
    if ($stockQ !== '' && mb_stripos($hay, $stockQ, 0, 'UTF-8') === false) return false;
    if ($stockDepartment !== '' && ($p['department'] ?? '') !== $stockDepartment) return false;
    if ($stockCategoryGroup !== '' && trim((string)($p['category_group'] ?? '')) !== $stockCategoryGroup) return false;
    if ($stockCategoryType !== '' && trim((string)($p['category_type'] ?? '')) !== $stockCategoryType) return false;
    if ($stockCategoryBrand !== '' && trim((string)($p['category_brand'] ?? '')) !== $stockCategoryBrand) return false;
    if ($stockCategorySpec !== '' && trim((string)($p['category_spec'] ?? '')) !== $stockCategorySpec) return false;
    if ($stockMinAvailable > 0 && stock_available($p) < $stockMinAvailable) return false;
    if ($stockCondition !== '' && ($p['product_condition'] ?? '') !== $stockCondition) return false;
    if ($stockWarehouse !== '' && ($p['warehouse_name'] ?? '') !== $stockWarehouse) return false;
    if ($stockShelf !== '' && ($p['shelf_code'] ?? '') !== $stockShelf) return false;
    if ($stockLayer !== '' && ($p['warehouse_location'] ?? '') !== $stockLayer) return false;
    if ($stockImageFilter === 'no_main' && trim((string)($p['image'] ?? '')) !== '') return false;
    if ($stockImageFilter === 'no_any' && product_images($p)) return false;
    $dateText = substr((string)($p['created_at'] ?? $p['imported_at'] ?? $p['updated_at'] ?? ''), 0, 10);
    if ($stockDateFrom !== '' && ($dateText === '' || $dateText < $stockDateFrom)) return false;
    if ($stockDateTo !== '' && ($dateText === '' || $dateText > $stockDateTo)) return false;
    return true;
}));
$stockFilteredTotal = count($stockFiltered);
$stockPages = max(1, (int)ceil($stockFilteredTotal / $stockLimit));
$stockPage = min($stockPage, $stockPages);
$stockShown = array_slice($stockFiltered, ($stockPage - 1) * $stockLimit, $stockLimit);
$stockPageUrl = function($page) use ($stockQ, $stockWarehouse, $stockShelf, $stockLayer, $stockCondition, $stockDepartment, $stockCategoryGroup, $stockCategoryType, $stockCategoryBrand, $stockCategorySpec, $stockMinAvailable, $stockImageFilter, $stockDateFrom, $stockDateTo) {
    return 'operations.php?' . http_build_query([
        'stock_q' => $stockQ,
        'stock_warehouse' => $stockWarehouse,
        'stock_shelf' => $stockShelf,
        'stock_layer' => $stockLayer,
        'stock_condition' => $stockCondition,
        'stock_department' => $stockDepartment,
        'stock_category_group' => $stockCategoryGroup,
        'stock_category_type' => $stockCategoryType,
        'stock_category_brand' => $stockCategoryBrand,
        'stock_category_spec' => $stockCategorySpec,
        'stock_min_available' => $stockMinAvailable,
        'stock_image_filter' => $stockImageFilter,
        'stock_date_from' => $stockDateFrom,
        'stock_date_to' => $stockDateTo,
        'stock_page' => $page,
    ]) . '#stock-search';
};
$shown = array_values(array_filter($schedules, function($s) use ($filterPay, $filterShip, $filterOrder, $filterClose) {
    if ($filterPay && ($s['payment_status'] ?? '未付款') !== $filterPay) return false;
    if ($filterShip && ($s['shipping_status'] ?? '未出貨') !== $filterShip) return false;
    if ($filterOrder && ($s['order_status'] ?? '待記單') !== $filterOrder) return false;
    $closed = strtotime($s['close_at'] ?? '') && strtotime($s['close_at']) <= time();
    if ($filterClose === 'open' && $closed) return false;
    if ($filterClose === 'closed' && !$closed) return false;
    return true;
}));
usort($shown, function($a, $b) { return strcmp($b['close_at'] ?? '', $a['close_at'] ?? ''); });
$scheduleQueue = $shown;
usort($scheduleQueue, function($a, $b) {
    $ta = (string)($a['scheduled_publish_at'] ?? $a['publish_at'] ?? '');
    $tb = (string)($b['scheduled_publish_at'] ?? $b['publish_at'] ?? '');
    $cmp = strcmp($ta, $tb);
    if ($cmp !== 0) return $cmp;
    return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
});
$memberShown = array_values(array_filter($members, function($m) use ($memberQ, $memberRisk) { return member_matches($m, $memberQ) && ($memberRisk === '' || ($m['blacklist_status'] ?? '正常') === $memberRisk || ($m['risk_level'] ?? '一般') === $memberRisk); }));
$opsMemberLimit = 10;
$opsMemberPage = max(1, (int)($_GET['member_page'] ?? 1));
$opsMemberTotal = count($memberShown);
$opsMemberPages = max(1, (int)ceil($opsMemberTotal / $opsMemberLimit));
$opsMemberPage = min($opsMemberPage, $opsMemberPages);
$memberShownPage = array_slice($memberShown, ($opsMemberPage - 1) * $opsMemberLimit, $opsMemberLimit);
$opsMemberPageUrl = function($page) use ($memberQ, $memberRisk) {
    return 'operations.php?' . http_build_query([
        'member_q' => $memberQ,
        'member_risk' => $memberRisk,
        'member_page' => $page,
    ]) . '#members';
};
$sumRevenue = $sumPaid = $sumCost = $sumProfit = $sumUnpaid = 0;
foreach ($schedules as $s) {
    $t = totals($s, product_by_id($products, $s['product_id'] ?? ''));
    $sumRevenue += $t['receivable'];
    $sumPaid += $t['paid'];
    $sumCost += $t['cost'];
    $sumProfit += $t['profit'];
    $sumUnpaid += $t['unpaid'];
}
$analyticsToday = date('Y-m-d');
$analyticsSevenStart = date('Y-m-d', strtotime('-6 days'));
$analyticsMonthStart = date('Y-m-01');
$analyticsMonthEnd = date('Y-m-t');
$financePeriods = [
    'all' => ['label' => '總計', 'start' => '1900-01-01', 'end' => $analyticsToday],
    'today' => ['label' => '今日', 'start' => $analyticsToday, 'end' => $analyticsToday],
    'seven' => ['label' => '近 7 天', 'start' => $analyticsSevenStart, 'end' => $analyticsToday],
    'month' => ['label' => '本月', 'start' => $analyticsMonthStart, 'end' => $analyticsMonthEnd],
];
$financeAnalytics = [];
$financeLossRows = [];
foreach ($financePeriods as $key => $period) {
    $row = ops_erp_finance_empty_row($period['label'], $period['start'], $period['end']);
    foreach ($stockMovements as $mv) {
        if (ops_is_payment_import_row($mv)) continue;
        $date = ops_document_date_value($mv);
        if ($date < $period['start'] || $date > $period['end']) continue;
        $flowType = ops_document_flow_type($mv);
        $qty = ops_movement_qty($mv);
        if ($qty <= 0) continue;
        $unitCost = (float)($mv['unit_cost'] ?? 0);
        $unitPrice = (float)($mv['unit_price'] ?? 0);
        $amount = ops_money_amount($mv, $qty * max($unitPrice, $unitCost));
        if ($flowType === 'purchase_in') {
            $row['stock_in_qty'] += $qty;
            $row['stock_in_cost'] += $amount;
        } elseif ($flowType === 'purchase_return') {
            $row['purchase_return_qty'] += $qty;
            $row['purchase_return_amount'] += $amount;
        } elseif ($flowType === 'sales_out') {
            $cost = $qty * $unitCost;
            $row['sales_qty'] += $qty;
            $row['sales_revenue'] += $amount;
            $row['sales_cost'] += $cost;
            $row['gross_profit'] += $amount - $cost;
            $row['order_count']++;
            if ($amount - $cost < 0) {
                $row['loss_amount'] += abs($amount - $cost);
                if ($key === 'month') {
                    $financeLossRows[] = [
                        'date' => $date,
                        'product_id' => $mv['product_id'] ?? '',
                        'title' => $mv['product_title'] ?? '',
                        'barcode' => $mv['barcode'] ?? '',
                        'qty' => $qty,
                        'revenue' => $amount,
                        'cost' => $cost,
                        'profit' => $amount - $cost,
                        'winner' => $mv['customer_name'] ?? ($mv['party_name'] ?? ''),
                    ];
                }
            }
        } elseif ($flowType === 'sales_return') {
            $returnCost = $qty * $unitCost;
            $row['sales_return_qty'] += $qty;
            $row['sales_return_amount'] += $amount;
            $row['sales_return_cost'] += $returnCost;
            $row['sales_revenue'] -= $amount;
            $row['sales_cost'] -= $returnCost;
            $row['gross_profit'] -= ($amount - $returnCost);
            if ($amount > $returnCost) $row['loss_amount'] += $amount - $returnCost;
        }
    }
    foreach ($schedules as $s) {
        $date = substr((string)($s['close_at'] ?? $s['publish_at'] ?? $s['created_at'] ?? ''), 0, 10);
        if ($date < $period['start'] || $date > $period['end']) continue;
        $hasWinner = trim((string)($s['winner'] ?? $s['winner_facebook'] ?? $s['winner_phone'] ?? '')) !== '';
        $hasAmount = (float)($s['winning_price'] ?? 0) > 0 || (float)($s['paid_amount'] ?? 0) > 0;
        if (!$hasWinner && !$hasAmount) continue;
        $p = product_by_id($products, $s['product_id'] ?? '');
        $t = totals($s, $p);
        $qty = (int)($s['quantity'] ?? 1);
        $row['sales_qty'] += $qty;
        $row['sales_revenue'] += $t['receivable'];
        $row['sales_cost'] += $t['cost'];
        $row['gross_profit'] += $t['profit'];
        $row['order_count']++;
        if ($t['profit'] < 0) {
            $row['loss_amount'] += abs($t['profit']);
            if ($key === 'month') {
                $financeLossRows[] = [
                    'date' => $date,
                    'product_id' => $s['product_id'] ?? '',
                    'title' => $p['title'] ?? ($s['product_title'] ?? ''),
                    'barcode' => $p['barcode'] ?? '',
                    'qty' => $qty,
                    'revenue' => $t['receivable'],
                    'cost' => $t['cost'],
                    'profit' => $t['profit'],
                    'winner' => trim((string)($s['winner'] ?? $s['winner_facebook'] ?? $s['winner_phone'] ?? '')),
                ];
            }
        }
    }
    foreach ($returns as $rt) {
        if (!empty($rt['movement_id'])) continue;
        $date = ops_document_date_value($rt);
        if ($date < $period['start'] || $date > $period['end']) continue;
        if (!in_array(($rt['status'] ?? ''), ['已退款','完成'], true)) continue;
        $qty = max(1, (float)($rt['return_qty'] ?? 1));
        $refund = ops_money_amount($rt, 0);
        $p = product_by_key($products, $rt['product_id'] ?? '');
        $returnCost = $qty * (float)($p['cost'] ?? 0);
        $row['sales_return_qty'] += $qty;
        $row['sales_return_amount'] += $refund;
        $row['sales_return_cost'] += $returnCost;
        $row['sales_revenue'] -= $refund;
        $row['sales_cost'] -= $returnCost;
        $row['gross_profit'] -= ($refund - $returnCost);
        if ($refund > $returnCost) $row['loss_amount'] += $refund - $returnCost;
    }
    $financeAnalytics[$key] = $row;
}
usort($financeLossRows, function($a, $b) { return $a['profit'] <=> $b['profit']; });
$financeLossRows = array_slice($financeLossRows, 0, 10);
$financeChartMax = 1;
foreach ($financeAnalytics as $an) {
    foreach (['sales_revenue', 'sales_cost', 'gross_profit', 'loss_amount', 'stock_in_cost', 'purchase_return_amount', 'sales_return_amount'] as $field) {
        $financeChartMax = max($financeChartMax, abs((float)($an[$field] ?? 0)));
    }
}
$fixedExpenseCurrentPeriod = date('Y-m');
$fixedExpenseCurrentRows = array_values(array_filter($fixedExpensePayments, function($row) use ($fixedExpenseCurrentPeriod) {
    return ($row['period'] ?? '') === $fixedExpenseCurrentPeriod;
}));
$fixedExpenseMonthPayable = array_sum(array_map(function($row) {
    return in_array(($row['status'] ?? ''), ['免付', '取消'], true) ? 0 : (float)($row['amount'] ?? 0);
}, $fixedExpenseCurrentRows));
$fixedExpenseMonthPaid = array_sum(array_map(function($row) {
    return ($row['status'] ?? '') === '已付款' ? (float)($row['amount'] ?? 0) : 0;
}, $fixedExpenseCurrentRows));
$fixedExpenseMonthOutstanding = array_sum(array_map(function($row) {
    return in_array(($row['status'] ?? ''), ['待付款', '待確認', '逾期'], true) ? (float)($row['amount'] ?? 0) : 0;
}, $fixedExpenseCurrentRows));
$fixedExpenseMonthOverdue = array_sum(array_map(function($row) {
    return ($row['status'] ?? '') === '逾期' ? (float)($row['amount'] ?? 0) : 0;
}, $fixedExpenseCurrentRows));
$fixedExpenseCashPaidThisMonth = array_sum(array_map(function($row) use ($fixedExpenseCurrentPeriod) {
    if (($row['status'] ?? '') !== '已付款') return 0;
    $cashMonth = substr((string)($row['paid_date'] ?? ''), 0, 7);
    if ($cashMonth === '') $cashMonth = substr((string)($row['due_date'] ?? ''), 0, 7);
    return $cashMonth === $fixedExpenseCurrentPeriod ? (float)($row['amount'] ?? 0) : 0;
}, $fixedExpensePayments));
$fixedExpenseBudgetThisMonth = array_sum(array_map(function($template) use ($fixedExpenseCurrentPeriod) {
    if (!fixed_expense_matches_period($template, $fixedExpenseCurrentPeriod, false)) return 0;
    return ($template['amount_type'] ?? '固定金額') === '固定金額' ? (float)($template['default_amount'] ?? 0) : 0;
}, $fixedExpenses));
$financeReportMonthRevenue = (float)($financeAnalytics['month']['sales_revenue'] ?? 0);
$financeReportMonthSalesCost = (float)($financeAnalytics['month']['sales_cost'] ?? 0);
$financeReportMonthGrossProfit = (float)($financeAnalytics['month']['gross_profit'] ?? 0);
$financeReportMonthNetAfterFixed = $financeReportMonthGrossProfit - $fixedExpenseCashPaidThisMonth;
$fixedAssetTotalCost = 0;
$fixedAssetAccumulatedDepreciation = 0;
$fixedAssetBookValue = 0;
$fixedAssetMonthDepreciation = 0;
$fixedAssetActiveCount = 0;
foreach ($fixedAssets as $asset) {
    $snapshot = fixed_asset_depreciation_snapshot($asset, date('Y-m-d'));
    $fixedAssetTotalCost += (float)($asset['acquisition_cost'] ?? 0);
    $fixedAssetAccumulatedDepreciation += (float)($snapshot['accumulated'] ?? 0);
    $fixedAssetBookValue += (float)($snapshot['book_value'] ?? 0);
    $fixedAssetMonthDepreciation += (float)($snapshot['monthly'] ?? 0);
    if (in_array(($asset['status'] ?? ''), ['使用中','閒置','維修中'], true)) $fixedAssetActiveCount++;
}
$financeReportMonthAfterDepreciation = $financeReportMonthNetAfterFixed - $fixedAssetMonthDepreciation;

$openOrders = count(array_filter($schedules, function($s) { return !in_array(($s['order_status'] ?? '待打單'), ['已出貨', '完成', '取消'], true); }));
$orderGroups = [];
foreach ($schedules as $s) {
    $buyer = [
        'name' => trim((string)($s['winner'] ?? '')),
        'facebook' => trim((string)($s['winner_facebook'] ?? '')),
        'phone' => trim((string)($s['winner_phone'] ?? '')),
        'address' => trim((string)($s['winner_address'] ?? '')),
    ];
    if (buyer_key($buyer) === '') continue;
    if (in_array(($s['order_status'] ?? '待打單'), ['已出貨', '完成', '取消'], true)) continue;
    $key = buyer_key($buyer);
    $p = product_by_id($products, $s['product_id'] ?? '');
    $t = totals($s, $p);
    if (!isset($orderGroups[$key])) {
        $orderGroups[$key] = [
            'buyer' => $buyer,
            'items' => [],
            'count' => 0,
            'receivable' => 0,
            'paid' => 0,
            'unpaid' => 0,
            'last_win_date' => '',
        ];
    }
    $orderGroups[$key]['items'][] = ['schedule' => $s, 'product' => $p, 'totals' => $t];
    $orderGroups[$key]['count']++;
    $orderGroups[$key]['receivable'] += $t['receivable'];
    $orderGroups[$key]['paid'] += $t['paid'];
    $orderGroups[$key]['unpaid'] += $t['unpaid'];
    $orderGroups[$key]['last_win_date'] = max($orderGroups[$key]['last_win_date'], substr((string)($s['close_at'] ?? $s['publish_at'] ?? ''), 0, 10));
}
uasort($orderGroups, function($a, $b) { return strcmp($b['last_win_date'], $a['last_win_date']); });
$paymentGroups = [];
foreach ($schedules as $s) {
    $buyer = ['name' => $s['winner'] ?? '', 'facebook' => $s['winner_facebook'] ?? '', 'phone' => $s['winner_phone'] ?? ''];
    if (buyer_key($buyer) === '') continue;
    $p = product_by_id($products, $s['product_id'] ?? '');
    $t = totals($s, $p);
    if ($t['unpaid'] <= 0) continue;
    if (in_array(($s['payment_status'] ?? ''), ['取消'], true)) continue;
    $key = buyer_key($buyer);
    if (!isset($paymentGroups[$key])) $paymentGroups[$key] = ['buyer' => $buyer, 'items' => [], 'unpaid' => 0, 'receivable' => 0];
    $paymentGroups[$key]['items'][] = ['schedule' => $s, 'product' => $p, 'totals' => $t];
    $paymentGroups[$key]['unpaid'] += $t['unpaid'];
    $paymentGroups[$key]['receivable'] += $t['receivable'];
}
uasort($paymentGroups, function($a, $b) { return $b['unpaid'] <=> $a['unpaid']; });

$nowTs = time();
$today = date('Y-m-d');
$scheduleUpcoming = [];
$scheduleClosingSoon = [];
$unshippedByBuyer = [];
$relistReminders = [];
foreach ($schedules as $s) {
    $publishAt = trim((string)($s['scheduled_publish_at'] ?? $s['publish_at'] ?? ''));
    $closeAt = trim((string)($s['close_remind_at'] ?? $s['close_at'] ?? ''));
    $publishTs = $publishAt !== '' ? strtotime($publishAt) : false;
    $closeTs = $closeAt !== '' ? strtotime($closeAt) : false;
    $publishStatus = trim((string)($s['publish_status'] ?? $s['status'] ?? ''));
    $orderStatus = trim((string)($s['order_status'] ?? ''));
    $shippingStatus = trim((string)($s['shipping_status'] ?? ''));
    $hasBuyer = trim((string)($s['winner'] ?? '')) !== '' || trim((string)($s['winner_facebook'] ?? '')) !== '' || trim((string)($s['winner_phone'] ?? '')) !== '';

    if ($publishTs !== false && $publishTs >= strtotime($today . ' 00:00:00') && $publishTs <= strtotime('+3 days') && !in_array($publishStatus, ['已上架', '上架成功'], true)) {
        $scheduleUpcoming[] = $s;
    }
    if ($closeTs !== false && $closeTs >= $nowTs && $closeTs <= strtotime('+24 hours') && !in_array($orderStatus, ['已結標', '完成', '取消'], true)) {
        $scheduleClosingSoon[] = $s;
    }
    if ($hasBuyer && !in_array($shippingStatus, ['已出貨', '完成'], true) && !in_array($orderStatus, ['完成', '取消'], true)) {
        $key = buyer_key(['name' => $s['winner'] ?? '', 'facebook' => $s['winner_facebook'] ?? '', 'phone' => $s['winner_phone'] ?? '']) ?: ('schedule:' . ($s['id'] ?? ''));
        if (!isset($unshippedByBuyer[$key])) {
            $unshippedByBuyer[$key] = ['buyer' => ['name' => $s['winner'] ?? '', 'facebook' => $s['winner_facebook'] ?? '', 'phone' => $s['winner_phone'] ?? ''], 'items' => [], 'unpaid' => 0, 'receivable' => 0];
        }
        $t = totals($s, product_by_id($products, $s['product_id'] ?? ''));
        $unshippedByBuyer[$key]['items'][] = $s;
        $unshippedByBuyer[$key]['unpaid'] += $t['unpaid'];
        $unshippedByBuyer[$key]['receivable'] += $t['receivable'];
    }
    if (mb_stripos($orderStatus, '棄標', 0, 'UTF-8') !== false || mb_stripos($orderStatus, '弃標', 0, 'UTF-8') !== false || mb_stripos($orderStatus, '取消', 0, 'UTF-8') !== false) {
        $relistReminders[] = $s;
    }
}
usort($scheduleUpcoming, function($a, $b) { return strcmp($a['scheduled_publish_at'] ?? $a['publish_at'] ?? '', $b['scheduled_publish_at'] ?? $b['publish_at'] ?? ''); });
usort($scheduleClosingSoon, function($a, $b) { return strcmp($a['close_at'] ?? '', $b['close_at'] ?? ''); });
$facebookDailyDate = facebook_daily_date((string)($_GET['fb_day'] ?? $_GET['date'] ?? ''));
$facebookDailyRows = facebook_daily_collect($schedules, $products, $postReplySets ?? [], $facebookDailyDate);
$facebookDailyCompare = facebook_daily_compare($facebookDailyRows, $facebookDailyDate);
$facebookDailyCodexText = facebook_daily_codex_text($facebookDailyRows, $facebookDailyCompare, $facebookDailyDate);
if (in_array((string)($_GET['partial'] ?? ''), ['facebook_daily', 'facebook_daily_text'], true)) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (($_GET['partial'] ?? '') === 'facebook_daily_text') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $facebookDailyCodexText;
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'date' => $facebookDailyDate,
        'generated_at' => date('c'),
        'compare' => $facebookDailyCompare,
        'rows' => $facebookDailyRows,
        'codex_text' => $facebookDailyCodexText,
        'instructions' => 'Codex 依 rows 的 need_post / need_pin_qa / need_remind / need_winner 執行。發文後請回填 post_url 並把 publish_status 改已上架。',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_GET['partial'] ?? '') === 'ops_status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $todayForStatus = date('Y-m-d');
    $onlineForStatus = 0;
    $pendingTodayForStatus = 0;
    $postedTodayForStatus = 0;
    foreach ($schedules as $ss) {
        $pub = (string)($ss['scheduled_publish_at'] ?? $ss['publish_at'] ?? '');
        $actual = (string)($ss['actual_publish_at'] ?? '');
        $status = (string)($ss['publish_status'] ?? $ss['status'] ?? '');
        $closeTs = strtotime((string)($ss['close_at'] ?? ''));
        if (substr($pub, 0, 10) === $todayForStatus && !in_array($status, ['已上架', '上架成功'], true)) $pendingTodayForStatus++;
        if (substr($actual, 0, 10) === $todayForStatus || ($status === '已上架' && substr($pub, 0, 10) === $todayForStatus)) $postedTodayForStatus++;
        if ($status === '已上架' && $closeTs && $closeTs > time()) $onlineForStatus++;
    }
    echo json_encode([
        'ok' => true,
        'time' => date('H:i:s'),
        'products' => count($products),
        'schedules' => count($schedules),
        'upcoming' => count($scheduleUpcoming),
        'closingSoon' => count($scheduleClosingSoon),
        'unshippedBuyers' => count($unshippedByBuyer),
        'relist' => count($relistReminders),
        'pendingToday' => $pendingTodayForStatus,
        'postedToday' => $postedTodayForStatus,
        'online' => $onlineForStatus,
        'facebookDaily' => $facebookDailyCompare,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, minimum-scale=1, user-scalable=no, viewport-fit=cover">
<title>寶輝電腦部營運後台</title>
<link rel="stylesheet" href="assets/app.css?v=form-wrap-20260817-01">
<style>
.ops-shell{min-height:100vh;display:grid;grid-template-columns:260px minmax(0,1fr);background:#f6f7f8}.ops-nav{position:sticky;top:0;height:100vh;max-height:100vh;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;padding:20px 14px 88px;background:#18201d;color:#fff;display:flex;flex-direction:column;gap:6px;scrollbar-gutter:stable}.ops-nav strong{display:block;padding:8px 10px 14px;font-size:18px}.ops-nav a{padding:11px 12px;border-radius:8px;color:#dfe7e2;text-decoration:none;font-weight:700}.ops-nav a:hover,.ops-nav a.active{background:#2c3b35;color:#fff}.ops-nav-group{border:1px solid rgba(255,255,255,.10);border-radius:10px;margin:4px 0;background:rgba(255,255,255,.03);overflow:visible;flex:0 0 auto}.ops-nav-group summary{cursor:pointer;list-style:none;padding:11px 12px;color:#fff;font-weight:900;display:flex;align-items:center;justify-content:space-between}.ops-nav-group summary::-webkit-details-marker{display:none}.ops-nav-group summary:after{content:'+';font-size:18px;line-height:1;color:#9fb3aa}.ops-nav-group[open] summary:after{content:'-'}.ops-nav-sub{display:flex;flex-direction:column;gap:4px;padding:0 8px 10px;max-height:none;overflow:visible}.ops-nav-sub a{font-size:14px;padding:9px 10px 9px 18px;border-radius:7px;color:#cbd8d1;background:transparent}.ops-nav-sub a.active{background:#31443c;color:#fff}.ops-nav a.active{background:#0f766e!important;color:#fff!important;box-shadow:inset 5px 0 0 #facc15,0 8px 18px rgba(0,0,0,.20);transform:translateX(2px)}.ops-nav-sub a.active{background:#0f766e!important;color:#fff!important;box-shadow:inset 4px 0 0 #facc15}.ops-nav-group.is-current{border-color:rgba(250,204,21,.75);background:rgba(15,118,110,.18)}.ops-nav-current-label{display:block;margin:0 10px 8px;padding:6px 9px;border-radius:999px;background:#facc15;color:#17201d;font-size:12px;font-weight:900}.ops-nav-sub a:before{content:'› ';color:#7dd3c7}.ops-wrap{padding:24px;max-width:1500px;width:100%;margin:0 auto}.ops-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}.ops-head h1{margin:0}.ops-head p{margin:8px 0 0}.ops-tab{display:none}.ops-tab.is-active{display:block}.metric-grid.ops-tab.is-active{display:grid}.ops-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.overview-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.overview-board{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.reminder-card{margin-bottom:0;position:relative;overflow:hidden}.reminder-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:6px}.reminder-card .section-head{padding-left:6px}.reminder-row{display:flex;justify-content:space-between;gap:12px;align-items:center;border-top:1px solid var(--line);padding:10px 0}.scene-metric{border-left:6px solid transparent}.scene-schedule{--scene:#2563eb;--scene-bg:#eff6ff;--scene-line:#bfdbfe}.scene-close{--scene:#d97706;--scene-bg:#fffbeb;--scene-line:#fde68a}.scene-ship{--scene:#dc2626;--scene-bg:#fff1f2;--scene-line:#fecdd3}.scene-relist{--scene:#7c3aed;--scene-bg:#f5f3ff;--scene-line:#ddd6fe}.scene-metric{background:linear-gradient(180deg,#fff,var(--scene-bg));border-left-color:var(--scene);border-color:var(--scene-line)}.reminder-card.scene-schedule,.reminder-card.scene-close,.reminder-card.scene-ship,.reminder-card.scene-relist{background:linear-gradient(180deg,#fff,var(--scene-bg));border-color:var(--scene-line)}.reminder-card.scene-schedule:before,.reminder-card.scene-close:before,.reminder-card.scene-ship:before,.reminder-card.scene-relist:before{background:var(--scene)}.reminder-card h2{color:#0f172a}.reminder-card .button-like{border-color:var(--scene-line);color:var(--scene);background:#fff}.reminder-card .status-pill{border-color:var(--scene-line);background:#fff;color:var(--scene);font-weight:800}.reminder-row:first-of-type{border-top:0}.ops-card{background:#fff;border:1px solid var(--line);border-radius:8px;padding:16px;margin-bottom:16px;box-shadow:var(--shadow)}.thumb{width:74px;height:54px;object-fit:cover;border-radius:6px;border:1px solid var(--line)}.settle-table{min-width:1800px}.member-picker{min-width:250px}.inline-actions{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.ops-card h2{margin-top:0}.status-pill{display:inline-flex;border:1px solid var(--line);border-radius:999px;padding:4px 8px;background:#fbfaf7;font-size:12px}.note-row{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.return-table{min-width:1300px}.order-group-card{border:1px solid var(--line);border-radius:8px;padding:14px;margin:14px 0;background:#fff}.order-group-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;border-bottom:1px solid var(--line);padding-bottom:10px;margin-bottom:10px}.order-group-head h3{margin:0}.order-total{text-align:right}.order-total strong{display:block;font-size:24px;color:#0f766e}.order-total small{display:block;color:#dc3545}.order-items-table{min-width:1200px}.payment-offset-panel{border:1px solid var(--line);border-radius:8px;padding:14px;background:#fbfaf7;margin:12px 0 16px}.payment-offset-card{border-top:1px solid var(--line);padding:12px 0}.payment-offset-head{display:grid;grid-template-columns:minmax(220px,.7fr) minmax(420px,1.3fr);gap:12px;align-items:end}.payment-inputs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.payment-offset-table{min-width:900px}.order-actions{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;align-items:end;margin-top:12px}@media(max-width:900px){.order-group-head{display:block}.order-total{text-align:left;margin-top:8px}.order-actions,.payment-offset-head,.payment-inputs{grid-template-columns:1fr}}.member-search-panel{display:grid;grid-template-columns:1.3fr 2fr;gap:16px}.member-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.member-workbench{display:grid;grid-template-columns:minmax(280px,.8fr) minmax(420px,1.4fr);gap:16px;margin-bottom:16px}.member-search-card,.member-editor-card{border:1px solid var(--line);border-radius:8px;padding:14px;background:#fbfaf7}.member-search-card h3,.member-editor-card h3{margin-top:0}.member-table{min-width:1500px}.member-table tr.is-blacklisted{background:#fff1f2}.member-table tr.is-watch{background:#fffbeb}.risk-reason{margin-top:6px;color:#b91c1c;font-weight:700}.danger-text{color:#dc3545}.barcode-code-panel{margin:14px 0;padding:14px;border:1px solid #d8e0ea;border-radius:10px;background:#f8fafc}.barcode-code-panel h3{margin:0 0 6px}.barcode-code-grid{display:grid;grid-template-columns:2fr 1fr;gap:12px}.barcode-code-grid h4{margin:8px 0}.code-chip{display:inline-flex;gap:6px;align-items:center;margin:4px 6px 4px 0;padding:5px 9px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#0f172a}.code-chip b{color:#0f766e}@media(max-width:760px){.barcode-code-grid{grid-template-columns:1fr}}.member-inline-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;min-width:360px}.member-inline-form textarea,.member-inline-form button{grid-column:1/-1}.mini-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.mini-form .wide{grid-column:1/-1}.stock-card-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.stock-card{border:1px solid #d8e0ea;border-radius:10px;background:#fff;padding:12px}.stock-card-check{display:flex;gap:6px;align-items:center;font-weight:800;color:#475569;margin-bottom:8px}.stock-card-main{display:grid;grid-template-columns:112px minmax(0,1fr);gap:12px;align-items:start}.stock-card-images{min-width:0}.stock-main-img,.stock-no-img{width:106px;height:106px;object-fit:cover;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-weight:800}.stock-thumb-row{display:flex;gap:5px;margin-top:6px;flex-wrap:wrap}.stock-thumb-row img{width:32px;height:32px;object-fit:cover;border:1px solid #cbd5e1;border-radius:6px}.stock-title{font-size:18px;font-weight:800;line-height:1.35}.stock-card-info .muted{font-size:13px}.stock-chip-row{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0}.stock-chip{display:inline-flex;align-items:center;border:1px solid #d8e0ea;border-radius:999px;padding:4px 8px;font-size:13px;font-weight:800}.stock-chip.category{background:#eff6ff;color:#1e3a8a;border-color:#bfdbfe}.stock-chip.spec{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}.stock-location{font-size:13px;color:#475569}.stock-numbers{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px}.stock-numbers span,.stock-numbers strong{border:1px solid #e2e8f0;border-radius:7px;padding:4px 7px;background:#f8fafc;font-size:13px}.stock-numbers strong{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.stock-cost-inline{display:flex;gap:8px;align-items:center;margin-top:8px;font-weight:800;color:#334155}.stock-cost-inline input{width:118px}.stock-empty{border:1px dashed #cbd5e1;border-radius:10px;padding:18px;text-align:center;background:#f8fafc}@media(max-width:900px){.ops-shell{grid-template-columns:1fr}.ops-nav{position:sticky;height:auto;z-index:4;overflow-x:auto;flex-direction:row;align-items:center}.ops-nav strong{display:none}.ops-nav a{white-space:nowrap}.ops-grid,.overview-metrics,.overview-board,.note-row,.member-search-panel,.member-summary-grid,.member-workbench,.mini-form,.stock-card-grid{grid-template-columns:1fr}.stock-card-main{grid-template-columns:88px minmax(0,1fr)}.stock-main-img,.stock-no-img{width:82px;height:82px}.ops-wrap{padding:14px}.settle-table,.return-table{min-width:1100px}.ops-head{display:block}}

.form-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.button-like{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#0f172a;text-decoration:none;font-weight:700}
.button-like.small,.danger.small{min-height:30px;padding:5px 9px;font-size:14px}
.inline-form{display:inline-block;margin:4px 0 0 6px}
.danger{border:0;border-radius:6px;background:#dc3545;color:#fff;font-weight:700;cursor:pointer}


.bulk-product-form{margin-top:10px}
.bulk-actions{display:flex;justify-content:flex-end;gap:10px;margin:8px 0}
.bulk-actions.bottom{margin-top:10px}
.product-check,#checkAllProducts{width:18px;height:18px}

.warehouse-panel{background:#1b121c;color:#f7e9d7;border:1px solid rgba(245,190,83,.35);border-radius:16px;padding:22px;box-shadow:0 18px 40px rgba(0,0,0,.18)}
.warehouse-panel h2{margin-top:0;color:#fff}
.warehouse-kicker{color:#f3bd4f;text-transform:uppercase;font-weight:800;letter-spacing:.06em;font-size:13px}
.warehouse-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:16px 0}
.warehouse-card{border:1px solid rgba(255,255,255,.16);border-radius:14px;padding:18px;background:rgba(255,255,255,.04)}
.warehouse-card b{display:block;color:#fff;margin-bottom:6px}
.warehouse-card .count{font-size:34px;color:#f3bd4f;font-weight:800;margin-top:16px}
.warehouse-manage-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.color-module-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.color-module-card{border:1px solid var(--line);border-radius:8px;padding:14px;background:#fff}.color-module-card-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}.color-module-edit-form{display:grid;grid-template-columns:1fr;gap:10px}.color-module-preview{border:1px solid var(--line);border-radius:8px;padding:10px;background:#fbfaf7}.color-module-preview b{display:block;margin:6px 0}.color-module-create{margin-bottom:16px}
.warehouse-tags{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.warehouse-tag{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(243,189,79,.45);background:rgba(243,189,79,.12);color:#f7c65d;border-radius:999px;padding:7px 10px;font-weight:800}
.warehouse-tag button{border:0;background:#f7c65d;color:#1b121c;border-radius:50%;width:20px;height:20px;font-weight:900;cursor:pointer}
.warehouse-note{border:1px solid rgba(80,220,210,.5);background:rgba(80,220,210,.08);color:#b9fffb;border-radius:12px;padding:12px;margin:14px 0}
.ops-sync-pill{display:inline-flex;align-items:center;gap:8px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;padding:8px 12px;color:#334155;font-size:13px;font-weight:700;white-space:nowrap}.product-code-picker{border:1px solid #d8e0ea;border-radius:10px;padding:10px;background:#f8fafc;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end;grid-column:span 2;min-width:0;max-width:100%}.product-code-picker label{margin:0;min-width:0}.product-code-picker select,.product-code-picker input,.product-code-picker .button-like{max-width:100%}.product-code-picker .button-like{white-space:nowrap}.product-picked-list{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;min-height:32px}.product-picked-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;padding:6px 10px;font-weight:800;color:#0f172a}.product-picked-chip b{color:#0f766e}.product-picked-chip button{border:0;background:#e2e8f0;border-radius:50%;width:20px;height:20px;cursor:pointer;font-weight:900;color:#334155}@media(max-width:760px){.product-code-picker{grid-template-columns:1fr;grid-column:1/-1}.product-code-picker .button-like{width:100%}}.ops-sync-pill.is-error{border-color:#fecaca;color:#b91c1c;background:#fff1f2}.warehouse-workflow-note{margin:12px 0;padding:12px 14px;border-left:5px solid #0f766e;background:#ecfdf5;border-radius:8px;color:#064e3b}.warehouse-advanced-add{margin:10px 0 14px;border:1px solid #d8e0ea;border-radius:10px;background:#fff;padding:10px}.warehouse-advanced-add summary{cursor:pointer;font-weight:800}.inline-layer-form{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin-left:8px}.inline-layer-form input{width:150px;padding:6px 8px;border:1px solid #cbd5e1;border-radius:6px}.warehouse-tree-tools{display:grid;grid-template-columns:minmax(160px,0.7fr) minmax(180px,0.8fr) minmax(220px,1fr);gap:10px;align-items:end;margin:14px 0}.warehouse-tree-tools .muted{grid-column:1/-1}.warehouse-layer-add-form,.warehouse-advanced-add .mini-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;align-items:end}.warehouse-layer-add-form button,.warehouse-advanced-add .mini-form button{grid-column:1/-1;width:100%}.warehouse-tree{display:grid;gap:14px;margin:14px 0 18px}.warehouse-tree-card{border:1px solid #d8e0ea;border-left:8px solid #0f766e;border-radius:12px;background:#fff;overflow:hidden}.warehouse-tree-head{display:flex;justify-content:space-between;gap:12px;align-items:center;background:#f0fdfa;padding:12px 14px;border-bottom:1px solid #d8e0ea}.warehouse-tree-head h3{margin:0}.warehouse-tree-shelf{display:grid;grid-template-columns:180px minmax(0,1fr);gap:10px;padding:12px 14px;border-top:1px solid #eef2f7}.warehouse-tree-shelf:first-of-type{border-top:0}.warehouse-tree-shelf b{color:#0f172a}.warehouse-layer-tags{display:flex;gap:8px;flex-wrap:wrap}.warehouse-layer-tag{display:inline-flex;align-items:center;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:999px;padding:5px 10px;font-weight:800}.warehouse-empty{padding:14px;color:#64748b}.warehouse-tree-card.is-hidden{display:none}.warehouse-raw-details{margin-top:16px;border:1px solid #d8e0ea;border-radius:10px;background:#fff;padding:12px}.warehouse-raw-details summary{cursor:pointer;font-weight:800;color:#0f172a}.warehouse-raw-details[open]{box-shadow:0 10px 24px rgba(15,23,42,.06)}@media(max-width:760px){.warehouse-tree-tools,.warehouse-tree-shelf,.warehouse-layer-add-form,.warehouse-advanced-add .mini-form{grid-template-columns:1fr}.warehouse-layer-add-form select,.warehouse-layer-add-form input,.warehouse-advanced-add select,.warehouse-advanced-add input,.warehouse-tree-tools select,.warehouse-tree-tools input{width:100%;max-width:100%;min-height:42px}.ops-shell{grid-template-columns:1fr}.ops-sync-pill{margin-top:8px}}
.stock-filter-grid{display:grid;grid-template-columns:1.4fr repeat(3,minmax(0,1fr));gap:12px}
@media(max-width:900px){.warehouse-grid,.warehouse-manage-grid,.stock-filter-grid{grid-template-columns:1fr}}
.ops-wrap,.ops-card,.product-form{min-width:0}
#productMasterForm,.product-form{align-items:stretch}
#productMasterForm > *,.product-form > *{min-width:0;max-width:100%}
.product-form label,.product-form select,.product-form input,.product-form textarea{max-width:100%;box-sizing:border-box}
.product-form label{overflow-wrap:anywhere}
.shelf-add-inline{display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap}
.shelf-add-inline input{flex:1 1 120px;min-width:0;min-height:36px}
.shelf-add-inline button{white-space:nowrap}
@media (max-width: 1320px) and (min-width: 901px) {
  .product-form,.schedule-form,.user-form{grid-template-columns:repeat(2,minmax(0,1fr))}
  .product-form .product-code-picker{grid-column:1/-1}
  .product-form .photo-capture-box,.product-form .wide{grid-column:1/-1}
}
@media (max-width: 900px) {
  .product-form .product-code-picker{grid-column:1/-1;grid-template-columns:1fr}
  .product-form .product-code-picker .button-like{width:100%}
}
.color-module-picker{grid-column:1/-1;border:1px solid #d8e0ea;border-radius:10px;padding:12px;background:#f8fafc;display:grid;gap:10px;min-width:0}
.color-module-picker-head{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end}
.color-module-picker-head label{margin:0}
.color-module-add-panel{border:1px dashed #94a3b8;border-radius:10px;padding:12px;background:#fff;display:grid;gap:10px}
.color-module-add-panel[hidden]{display:none!important}
.color-module-add-panel h3{margin:0;font-size:15px}
.color-module-add-actions{display:flex;gap:8px;flex-wrap:wrap}
.color-module-add-actions button{min-height:40px}
.color-module-system-note{margin:0;color:#475569;font-size:13px;font-weight:700}
@media (max-width: 900px) {
  .color-module-picker-head{grid-template-columns:1fr}
  .color-module-picker-head .button-like,.color-module-add-actions button{width:100%}
}
.spec-combo{grid-column:1/-1;position:relative;z-index:auto;overflow:visible;border:1px solid #d8e0ea;border-radius:10px;padding:12px;background:#f8fafc;display:grid;gap:8px;min-width:0}
.spec-combo-head{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end}
.spec-combo-head label{margin:0}
.spec-combo-menu{position:relative;left:auto;right:auto;top:auto;z-index:1;max-height:min(220px,32vh);overflow:auto;border:1px solid #cbd5e1;border-radius:10px;background:#fff;box-shadow:0 8px 18px rgba(15,23,42,.08)}
.spec-combo-menu[hidden]{display:none!important}
.spec-combo-option,.spec-combo-empty{display:block;width:100%;text-align:left;border:0;background:#fff;padding:10px 12px;font-weight:700;color:#0f172a;cursor:pointer}
.spec-combo-option:hover,.spec-combo-option.is-active{background:#f0fdfa}
.spec-combo-empty{color:#64748b;cursor:default}
.supplier-category-bar{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 14px}
.supplier-category-bar .stock-chip{cursor:pointer}
.supplier-category-bar .stock-chip.is-active{background:#0f766e;color:#fff;border-color:#0f766e}
.source-combo-status{min-height:18px}
.record-combo{grid-column:auto;z-index:auto}
.record-combo.is-wide{grid-column:1/-1}
@media (max-width: 900px) {
  .spec-combo-head{grid-template-columns:1fr}
  .spec-combo-head .button-like{width:100%;min-height:42px}
}

.product-image-guide{border:1px solid #bfdbfe;border-left:6px solid #2563eb;border-radius:10px;background:#eff6ff;padding:12px;color:#1e3a8a;font-weight:700}.product-upload-preview{border:1px solid #d8e0ea;border-radius:10px;background:#f8fafc;padding:12px;display:grid;grid-template-columns:180px minmax(0,1fr);gap:14px}.product-upload-preview[hidden]{display:none}.product-upload-preview h3{margin:0 0 8px;font-size:16px}.product-upload-main img{width:150px;height:150px;object-fit:cover;border:2px solid #2563eb;border-radius:10px;background:#fff;cursor:pointer}.product-upload-gallery{display:flex;gap:10px;flex-wrap:wrap}.product-upload-gallery img{width:76px;height:76px;object-fit:cover;border:1px solid #cbd5e1;border-radius:8px;background:#fff;cursor:pointer}.product-upload-name{font-size:12px;color:#475569;margin-top:4px;max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.product-image-preview{display:grid;grid-template-columns:180px minmax(0,1fr);gap:14px;border:1px solid #d8e0ea;border-radius:10px;background:#fbfaf7;padding:12px}.product-image-preview h3{margin:0 0 8px;font-size:16px}.product-main-preview img{width:150px;height:150px;object-fit:cover;border:2px solid #0f766e;border-radius:10px;cursor:pointer;background:#fff}.product-gallery{display:flex;gap:10px;flex-wrap:wrap}.product-gallery img{width:76px;height:76px;object-fit:cover;border:1px solid #cbd5e1;border-radius:8px;cursor:pointer;background:#fff}.product-gallery .empty{color:#64748b}.product-list-gallery{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}.product-list-gallery img{width:34px;height:34px;object-fit:cover;border:1px solid #cbd5e1;border-radius:5px;cursor:pointer}.product-list-main{display:block;margin-bottom:4px}.product-list-main img{border:2px solid #0f766e}.schedule-product-preview,.selected-product-panel{border:1px solid #d8e0ea;border-radius:8px;padding:12px;background:#f8fafc;color:#475569}.product-search-results{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.product-result-card{display:grid;grid-template-columns:64px 1fr;gap:10px;align-items:start;text-align:left;border:1px solid #d8e0ea;border-radius:8px;background:#fff;padding:8px;cursor:pointer;color:#0f172a}.product-result-card:hover{border-color:#0f766e;background:#f0fdfa}.product-result-card img,.product-result-card .no-img{width:60px;height:60px;border-radius:6px;object-fit:cover;border:1px solid #e5e7eb;display:flex;align-items:center;justify-content:center;background:#f8fafc;font-size:12px}.product-result-card small{display:block;color:#64748b}.danger-text{color:#dc3545}
.product-result-card.is-zero-stock{border-color:#fbbf24;background:#fffbeb}.product-result-card.is-zero-stock:hover{border-color:#d97706;background:#fff7d6}.product-result-card .stock-warning{color:#b45309;font-weight:900}.schedule-search-hint{display:block;margin-top:6px;color:#475569;font-size:13px;font-weight:700}
.photo-capture-box{grid-column:1/-1;border:1px solid #bbf7d0;border-left:6px solid #0f766e;border-radius:12px;background:#f0fdfa;padding:12px;display:grid;gap:10px}
.photo-capture-box > b{color:#064e3b;font-size:16px}
.photo-capture-box > .muted{margin:0}
.photo-capture-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.photo-capture-col{display:grid;gap:8px;min-width:0}
.photo-capture-col > span{font-weight:800;color:#0f172a}
.photo-capture-actions{display:flex;flex-wrap:wrap;gap:8px}
.photo-btn{position:relative;display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 14px;border-radius:10px;font-weight:800;cursor:pointer;overflow:hidden;margin:0;user-select:none}
.photo-btn input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;font-size:0}
.photo-btn.camera{background:#0f766e;color:#fff;border:0}
.photo-btn.album{background:#fff;color:#0f172a;border:1px solid #cbd5e1}
.photo-btn.paste{background:#1d4ed8;color:#fff;border:0}
.photo-btn.paste.is-waiting{background:#f59e0b;color:#111}
.photo-btn.small{min-height:34px;padding:6px 10px;font-size:13px;border-radius:8px}
.product-form .photo-btn,.quick-image-form .photo-btn{display:inline-flex;gap:0;align-items:center;justify-content:center}
.product-form .photo-btn.paste,.quick-image-form .photo-btn.paste{color:#fff}
.product-form .photo-btn input,.quick-image-form .photo-btn input,.photo-btn input{width:100%;height:100%;border:0;padding:0;margin:0;background:transparent;opacity:0!important}
.stock-card-images .photo-capture-actions{margin-top:8px;flex-direction:column}
.stock-card-images .photo-btn,.stock-card-images .product-capture-button{width:100%;margin-left:0}
#stock-search .quick-image-form .photo-capture-grid{grid-template-columns:1fr}
.photo-btn + .product-capture-button,.product-capture-button + .photo-btn{margin-left:6px}
.photo-inline-preview{display:flex;gap:8px;flex-wrap:wrap}
.photo-inline-preview img{width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #cbd5e1;background:#fff}
@media(max-width:760px){
  .photo-capture-grid{grid-template-columns:1fr}
  .photo-capture-actions .photo-btn{flex:1 1 calc(50% - 8px);min-height:48px}
  .product-upload-preview,.product-image-preview{grid-template-columns:1fr}
}
.schedule-preview-grid{display:grid;grid-template-columns:120px 1fr;gap:14px;align-items:start}
.schedule-preview-main{width:110px;height:110px;object-fit:cover;border-radius:8px;border:1px solid #d8e0ea;cursor:pointer}
.schedule-thumbs{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.schedule-thumbs img{width:58px;height:58px;object-fit:cover;border-radius:6px;border:2px solid transparent;cursor:pointer}
.schedule-thumbs img.is-selected{border-color:#0f766e}
.schedule-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:14px 0}
.image-lightbox{position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;align-items:center;justify-content:center;z-index:9999}
.image-lightbox img{max-width:92vw;max-height:90vh;border-radius:10px;background:#fff}
.image-lightbox.is-open{display:flex}
@media(max-width:900px){.schedule-stats,.schedule-preview-grid,.product-search-results{grid-template-columns:1fr}}


.settlement-card{position:relative;overflow:hidden;border-left:9px solid var(--product-accent,#0f766e);background:linear-gradient(180deg,var(--product-bg,#fff),#fff);box-shadow:0 8px 22px rgba(15,23,42,.07)}
.settlement-card:before{content:"";position:absolute;left:0;right:0;top:0;height:5px;background:var(--product-accent,#0f766e)}
.settlement-card > h3{display:flex;align-items:center;gap:10px;margin:-2px -2px 14px;padding:12px 14px;border-radius:6px;background:var(--product-head,#eef7f4);color:#0f172a;border:1px solid var(--product-line,#cde6dc)}
.settlement-card > h3:before{content:"";width:13px;height:13px;border-radius:999px;background:var(--product-accent,#0f766e);box-shadow:0 0 0 4px rgba(255,255,255,.9)}
.settlement-card .settlement-summary span,.settlement-card .note-row label{background:rgba(255,255,255,.72);border-color:var(--product-line,#d8e0ea)}
.settlement-card.color-set-1{--product-accent:#0f766e;--product-bg:#f0fdfa;--product-head:#ccfbf1;--product-line:#99f6e4}
.settlement-card.color-set-2{--product-accent:#2563eb;--product-bg:#eff6ff;--product-head:#dbeafe;--product-line:#bfdbfe}
.settlement-card.color-set-3{--product-accent:#d97706;--product-bg:#fffbeb;--product-head:#fef3c7;--product-line:#fde68a}
.settlement-card.color-set-4{--product-accent:#7c3aed;--product-bg:#f5f3ff;--product-head:#ede9fe;--product-line:#ddd6fe}
.settlement-card.color-set-5{--product-accent:#db2777;--product-bg:#fdf2f8;--product-head:#fce7f3;--product-line:#fbcfe8}
.settlement-card.color-set-6{--product-accent:#16a34a;--product-bg:#f0fdf4;--product-head:#dcfce7;--product-line:#bbf7d0}


.settlement-card .settlement-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:-2px -2px 14px;padding:12px 14px;border-radius:6px;background:var(--product-head,#eef7f4);border:1px solid var(--product-line,#cde6dc)}
.settlement-card .settlement-title b{display:flex;align-items:center;gap:10px;font-size:20px;color:#0f172a}
.settlement-card .settlement-title b:before{content:"";width:14px;height:14px;border-radius:999px;background:var(--product-accent,#0f766e);box-shadow:0 0 0 4px rgba(255,255,255,.9)}
.settlement-card .settlement-title button{background:#fff;border-color:var(--product-line,#cde6dc);color:var(--product-accent,#0f766e)}


.winner-batch-panel{border:1px solid #bfdbfe;border-left:8px solid #2563eb;border-radius:8px;padding:16px;margin:14px 0 18px;background:linear-gradient(180deg,#eff6ff,#fff)}
.winner-batch-head{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;border-bottom:1px solid #bfdbfe;padding-bottom:12px;margin-bottom:12px}
.winner-batch-head h3{margin:0 0 6px;color:#1e3a8a}
.winner-batch-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end}
.winner-batch-grid .wide{grid-column:1 / span 2}
.winner-batch-toolbar{background:#fff;border:1px solid #dbeafe;border-radius:8px;padding:10px;margin-top:12px}
.winner-batch-table{min-width:1100px}
.winner-batch-table tr.needs-buyer{background:#fff7ed}
.winner-batch-table tr.has-buyer{background:#f8fafc}
@media(max-width:900px){.winner-batch-head{display:block}.winner-batch-grid{grid-template-columns:1fr}.winner-batch-grid .wide{grid-column:auto}}


/* 2026-07-08 schedule work-card layout: replace the oversized grid-like table with operational cards. */
.schedule-card-list{display:grid;gap:16px;margin-top:14px}
.schedule-work-card{display:grid;grid-template-columns:44px 180px minmax(260px,1fr) minmax(360px,.95fr);gap:18px;align-items:stretch;border:1px solid #d8e0ea;border-radius:14px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.07);padding:16px;position:relative;overflow:hidden}
.schedule-work-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:7px;background:var(--schedule-accent,#0f766e)}
.schedule-work-card.is-posted{--schedule-accent:#16a34a}.schedule-work-card.is-failed{--schedule-accent:#dc2626}.schedule-work-card.is-closed{--schedule-accent:#64748b}.schedule-work-card.is-pending{--schedule-accent:#2563eb}
.schedule-card-select{display:flex;align-items:flex-start;justify-content:center;padding-top:8px}.schedule-card-select input{width:20px;height:20px}
.schedule-card-media{display:flex;flex-direction:column;gap:10px}.schedule-card-img,.schedule-card-noimg{width:170px;height:128px;border-radius:12px;border:1px solid #d8e0ea;background:#f8fafc;object-fit:cover;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-weight:900;cursor:pointer}
.schedule-card-id{font-size:22px;font-weight:900;color:#0f172a;line-height:1.1}.schedule-card-title{font-size:17px;font-weight:800;line-height:1.35;color:#1f2937;margin-top:4px}.schedule-card-spec{margin-top:6px;color:#64748b;font-weight:700}.schedule-card-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:14px}.schedule-meta-box{border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;padding:10px}.schedule-meta-box span{display:block;color:#64748b;font-size:12px;font-weight:800;margin-bottom:4px}.schedule-meta-box b{font-size:16px;color:#0f172a}.schedule-status-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.schedule-status-chip{display:inline-flex;align-items:center;border:1px solid #dbeafe;background:#eff6ff;color:#1e40af;border-radius:999px;padding:5px 9px;font-weight:800;font-size:13px}.schedule-status-chip.closed{background:#f1f5f9;color:#475569;border-color:#cbd5e1}.schedule-status-chip.posted{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.schedule-status-chip.failed{background:#fff1f2;color:#be123c;border-color:#fecdd3}
.schedule-card-actions{border-left:1px solid #e2e8f0;padding-left:16px}.schedule-card-actions .mini-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;align-items:end}.schedule-card-actions .mini-form .wide{grid-column:1/-1}.schedule-card-actions textarea{min-height:118px;font-size:13px;line-height:1.45}.schedule-card-actions .form-button-row{display:flex;gap:8px;flex-wrap:wrap}.schedule-card-actions .form-button-row button{flex:1 1 120px}.schedule-card-actions .inline-form{margin:8px 0 0}.schedule-card-actions .danger.small{width:100%;min-height:34px}
@media(max-width:1180px){.schedule-work-card{grid-template-columns:38px 150px minmax(0,1fr)}.schedule-card-actions{grid-column:2/-1;border-left:0;border-top:1px solid #e2e8f0;padding-left:0;padding-top:14px}.schedule-card-img,.schedule-card-noimg{width:146px;height:112px}}
@media(max-width:760px){.schedule-work-card{grid-template-columns:32px minmax(0,1fr);gap:12px}.schedule-card-media{grid-column:2}.schedule-card-img,.schedule-card-noimg{width:100%;height:190px}.schedule-card-main{grid-column:2}.schedule-card-actions{grid-column:2}.schedule-card-meta,.schedule-card-actions .mini-form{grid-template-columns:1fr}}
.schedule-bulk-bar{background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:10px;margin:12px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap}.schedule-bulk-bar .danger-button{margin-left:auto}@media(max-width:900px){.schedule-bulk-bar .danger-button{margin-left:0;width:100%}}

.buyer-reconcile-panel{border:1px solid #c7d2fe;border-left:8px solid #4f46e5;border-radius:8px;padding:16px;margin:14px 0 18px;background:linear-gradient(180deg,#eef2ff,#fff)}
.section-head.compact{margin-bottom:10px}.buyer-reconcile-filter{margin:8px 0 12px}.buyer-reconcile-card{border:1px solid #dbe3ec;border-radius:8px;background:#fff;padding:14px;margin:12px 0}.buyer-reconcile-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;border-bottom:1px solid #e5e7eb;padding-bottom:10px;margin-bottom:10px}.buyer-reconcile-head b{font-size:20px}.buyer-total{text-align:right}.buyer-total strong{display:block;font-size:24px;color:#0f766e}.buyer-total small{display:block;color:#dc2626}.buyer-reconcile-table{min-width:1050px}.buyer-reconcile-card textarea{width:100%;margin-top:10px}
@media(max-width:900px){.buyer-reconcile-head{display:block}.buyer-total{text-align:left;margin-top:8px}}

.operator-notice { border:1px solid #b7e4d5; background:#ecfdf5; color:#065f46; border-radius:10px; padding:12px 14px; font-weight:700; }
.schedule-job-builder { border:1px solid #d6e1ef; border-radius:12px; padding:16px; background:#f8fafc; }
.schedule-job-rows { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:14px; align-items:start; }
.schedule-job-row { display:grid; grid-template-columns:1fr; gap:10px; align-items:stretch; padding:14px; border:1px solid #cfe0f4; border-left:6px solid #0f766e; border-radius:12px; background:#fff; box-shadow:0 8px 20px rgba(15, 23, 42, .06); }
.schedule-job-index { width:100%; min-height:36px; border-radius:8px; display:grid; place-items:center; font-weight:900; color:#0f766e; background:#dff5ee; }
.schedule-job-actions { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
.schedule-job-actions button { min-width:70px; }
.schedule-job-toolbar { display:flex; gap:12px; align-items:center; margin-top:12px; flex-wrap:wrap; }
.section-head.compact { margin:0 0 12px; }
.section-head.compact h3 { margin:0; font-size:22px; }
@media (max-width: 640px) {
  .schedule-job-rows { grid-template-columns:1fr; }
}


.schedule-product-picker{border:1px solid #d6e1ef;border-radius:12px;padding:14px;background:#f8fafc;color:#0f172a}
.picker-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px;flex-wrap:wrap}
.product-result-list{display:grid;gap:10px}
.product-result-list:empty{display:none}
.product-result-card{width:100%;display:grid;grid-template-columns:84px minmax(0,1fr) auto;gap:14px;align-items:center;text-align:left;border:1px solid #dbe5f2;border-radius:12px;padding:12px;background:#fff;cursor:pointer;color:#0f172a}
.product-result-card:hover,.product-result-card.is-pending{border-color:#0f766e;box-shadow:0 0 0 3px rgba(15,118,110,.12)}
.product-result-card img,.product-result-card .no-img{width:84px;height:84px;object-fit:cover;border-radius:8px;border:1px solid #dbe5f2;background:#fff;display:grid;place-items:center}
.product-result-card span{display:grid;gap:3px}
.product-result-card small{color:#475569}
.product-pick-action{justify-self:end;min-width:110px}
.product-confirm-bar{margin-top:12px;display:flex;align-items:center;justify-content:space-between;gap:12px;border-top:1px solid #dbe5f2;padding-top:12px}
.schedule-product-preview.is-confirmed{border-color:#0f766e;background:#ecfdf5}
@media(max-width:760px){.product-result-card{grid-template-columns:72px 1fr}.product-pick-action{grid-column:1/-1;width:100%}.product-confirm-bar{align-items:stretch;flex-direction:column}}


.settlement-flow-help{border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:12px;padding:14px;margin:12px 0 18px}
.settlement-flow-help strong{display:block;margin-bottom:6px;color:#0f172a}
.settlement-notice-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px}
.settlement-empty-state{border:1px dashed #cbd5e1;background:#f8fafc;border-radius:12px;padding:18px;margin:16px 0;color:#334155}


.order-empty-workflow{border:1px solid #bfdbfe;background:#eff6ff;border-radius:14px;padding:18px;margin:18px 0;color:#1e3a8a}
.order-empty-workflow h3{margin:0 0 10px;color:#0f172a}
.order-flow-steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:14px 0}
.order-flow-step{background:#fff;border:1px solid #dbeafe;border-radius:12px;padding:12px;color:#334155}
.order-flow-step b{display:block;color:#0f172a;margin-bottom:5px}
.order-empty-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}


.colored-section{position:relative;overflow:hidden;border-left-width:8px!important;background:linear-gradient(180deg,var(--section-bg),#fff)!important;border-color:var(--section-line)!important}
.colored-section:before{content:"";position:absolute;left:0;right:0;top:0;height:5px;background:var(--section-accent)}
.colored-section h2{display:flex;align-items:center;gap:10px;color:#0f172a}
.colored-section h2:before{content:"";width:14px;height:14px;border-radius:999px;background:var(--section-accent);box-shadow:0 0 0 4px rgba(255,255,255,.85)}
.colored-section .product-form{border:1px solid var(--section-line);border-radius:10px;background:rgba(255,255,255,.78);padding:14px}
.colored-section .product-form label{background:#fff;border:1px solid rgba(203,213,225,.9);border-radius:8px;padding:8px}
.colored-section .table-wrap{border:1px solid var(--section-line);border-radius:10px;background:#fff}
.colored-section thead th{background:var(--section-head)!important;color:#0f172a}
.stock-in-section{--section-accent:#0f766e;--section-bg:#ecfdf5;--section-line:#99f6e4;--section-head:#ccfbf1}
.supplier-section{--section-accent:#7c3aed;--section-bg:#f5f3ff;--section-line:#ddd6fe;--section-head:#ede9fe}
.supplier-section .primary{background:#7c3aed}
.ops-no-access{display:none!important}
.staff-permission-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.permission-group{border:1px solid var(--line);border-radius:8px;padding:12px;background:#fbfaf7}
.permission-group h3{margin:0 0 8px;font-size:16px}
.permission-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.analytics-chart-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:14px 0}
.analytics-chart-card{border:1px solid var(--line);border-radius:10px;background:#fff;padding:14px}
.analytics-chart-card h3{margin:0 0 10px}
.chart-row{display:grid;grid-template-columns:82px minmax(0,1fr) 92px;gap:10px;align-items:center;margin:10px 0}
.chart-track{height:14px;border-radius:999px;background:#eef2f7;overflow:hidden}
.chart-bar{height:100%;border-radius:999px;background:#0f766e;min-width:2px}
.chart-bar.cost{background:#2563eb}.chart-bar.loss{background:#dc3545}.chart-bar.profit{background:#16a34a}
.chart-value{text-align:right;font-weight:800}
@media(max-width:900px){.staff-permission-grid,.permission-checks,.analytics-chart-grid{grid-template-columns:1fr}}
body.is-embed{height:100%;margin:0}
body.is-embed .ops-shell{min-height:100%;height:100%}
body.is-embed .ops-nav{height:100%;max-height:100%}
@media(max-width:820px){
  body.is-embed,body.is-embed .ops-shell{height:auto!important;min-height:100%!important;overflow:visible!important}
  body.is-embed .ops-nav{height:auto!important;max-height:none!important}
}

html,body{height:100%;margin:0}
body{overflow:hidden}
.ops-shell{height:100%;min-height:0;overflow:hidden;align-items:stretch}
.ops-nav{position:relative;top:auto;height:100%;max-height:100%;min-height:0;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain}
.ops-main{min-width:0;min-height:0;height:100%;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain}
@media print{
  html,body{height:auto;overflow:visible}
  .ops-shell,.ops-main,.ops-nav,.ops-wrap{height:auto;max-height:none;overflow:visible}
}

/* 2026-07-08 schedule card polish: one work schedule = one clear card row inside admin embed. */
.schedule-card-list{display:grid;gap:18px;margin-top:16px;max-width:100%;overflow:visible}
.schedule-work-card{display:grid;grid-template-columns:34px 240px minmax(320px,1fr);gap:18px;align-items:start;border:1px solid #d7e1ee;border-radius:10px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,.06);padding:18px 18px 16px 20px;position:relative;overflow:hidden}
.schedule-work-card:before{content:"";position:absolute;left:0;top:0;bottom:0;width:8px;background:var(--schedule-accent,#0f766e)}
.schedule-card-media{display:flex;flex-direction:column;gap:10px;min-width:0}.schedule-card-img,.schedule-card-noimg{width:240px;height:180px;border-radius:8px;border:1px solid #cbd5e1;background:#f8fafc;object-fit:cover;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-weight:900;cursor:pointer}.schedule-card-media .status-pill{width:240px;text-align:center;justify-content:center}
.schedule-card-main{min-width:0}.schedule-card-id{font-size:24px;font-weight:900;color:#0f172a;line-height:1.1}.schedule-queue-no{display:inline-block;margin:0 8px 6px 0;padding:3px 9px;border-radius:999px;background:#0f766e;color:#fff;font-size:13px;font-weight:800;vertical-align:middle}.schedule-card-title{font-size:18px;font-weight:800;line-height:1.4;color:#1f2937;margin-top:5px}.schedule-card-spec{margin-top:7px;color:#475569;font-weight:800}.schedule-card-meta{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:16px}.schedule-meta-box{border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:10px;min-height:68px}.schedule-meta-box span{display:block;color:#64748b;font-size:12px;font-weight:800;margin-bottom:5px}.schedule-meta-box b{font-size:15px;color:#0f172a;word-break:break-word}.schedule-status-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.schedule-card-actions{grid-column:1/-1;border-left:0;border-top:1px solid #e2e8f0;padding:14px 0 0;margin-top:2px}.schedule-card-actions .mini-form{display:grid;grid-template-columns:180px 220px minmax(260px,1fr) 150px 150px;gap:10px;align-items:end}.schedule-card-actions .mini-form .wide{grid-column:auto}.schedule-card-actions .mini-form label.wide:nth-of-type(3){grid-column:3/5}.schedule-card-actions textarea{min-height:104px;font-size:13px;line-height:1.45}.schedule-card-actions .form-button-row{display:flex;gap:8px;flex-wrap:wrap;grid-column:1/-1}.schedule-card-actions .form-button-row button{flex:1 1 130px}.schedule-card-actions .inline-form{margin:10px 0 0;display:flex;justify-content:flex-end}.schedule-card-actions .danger.small{width:auto;min-width:150px;min-height:36px}
@media(max-width:1200px){.schedule-work-card{grid-template-columns:32px 190px minmax(0,1fr)}.schedule-card-img,.schedule-card-noimg{width:190px;height:148px}.schedule-card-media .status-pill{width:190px}.schedule-card-meta{grid-template-columns:repeat(2,minmax(0,1fr))}.schedule-card-actions .mini-form{grid-template-columns:repeat(2,minmax(0,1fr))}.schedule-card-actions .mini-form .wide,.schedule-card-actions .mini-form label.wide:nth-of-type(3){grid-column:1/-1}}
@media(max-width:760px){.schedule-work-card{grid-template-columns:28px minmax(0,1fr);gap:12px;padding:14px 12px 14px 16px}.schedule-card-media,.schedule-card-main,.schedule-card-actions{grid-column:2}.schedule-card-img,.schedule-card-noimg,.schedule-card-media .status-pill{width:100%;height:auto}.schedule-card-img,.schedule-card-noimg{aspect-ratio:4/3}.schedule-card-meta,.schedule-card-actions .mini-form{grid-template-columns:1fr}}

/* 2026-07-08 final schedule card layout: larger image, cleaner one-row work item, actions below. */
.ops-shell .schedule-card-list{display:grid!important;gap:16px!important;margin-top:18px!important;max-width:100%!important;overflow:visible!important}
.ops-shell .schedule-work-card{display:grid!important;grid-template-columns:42px 320px minmax(0,1fr)!important;gap:20px!important;align-items:start!important;border:1px solid #d7e1ee!important;border-radius:12px!important;background:#fff!important;box-shadow:0 10px 24px rgba(15,23,42,.06)!important;padding:18px 18px 16px 22px!important;position:relative!important;overflow:hidden!important}
.ops-shell .schedule-card-media{min-width:0!important;gap:10px!important}
.ops-shell .schedule-card-img,.ops-shell .schedule-card-noimg{width:320px!important;height:230px!important;border-radius:10px!important;object-fit:cover!important;background:#f8fafc!important;border:1px solid #cbd5e1!important}
.ops-shell .schedule-card-media .status-pill{width:320px!important;justify-content:center!important;text-align:center!important}
.ops-shell .schedule-card-main{min-width:0!important;padding-top:2px!important}
.ops-shell .schedule-card-id{font-size:25px!important;font-weight:900!important;color:#0f172a!important}
.ops-shell .schedule-card-title{font-size:19px!important;line-height:1.45!important;margin-top:6px!important;color:#1f2937!important}
.ops-shell .schedule-card-spec{font-size:15px!important;color:#475569!important;margin-top:8px!important}
.ops-shell .schedule-card-meta{display:grid!important;grid-template-columns:repeat(4,minmax(150px,1fr))!important;gap:10px!important;margin-top:16px!important}
.ops-shell .schedule-meta-box{min-height:68px!important;border-radius:9px!important;background:#f8fafc!important}
.ops-shell .schedule-card-actions{grid-column:1/-1!important;border-left:0!important;border-top:1px solid #e2e8f0!important;padding:15px 0 0!important;margin-top:4px!important;background:#fff!important}
.ops-shell .schedule-card-actions .mini-form{display:grid!important;grid-template-columns:180px 220px minmax(260px,1fr) 150px 150px!important;gap:10px!important;align-items:end!important}
.ops-shell .schedule-card-actions .mini-form .wide{grid-column:auto!important}
.ops-shell .schedule-card-actions textarea{min-height:96px!important;line-height:1.45!important}
.ops-shell .schedule-card-actions .form-button-row{grid-column:1/-1!important;display:flex!important;gap:10px!important;flex-wrap:wrap!important}
.ops-shell .schedule-card-actions .form-button-row button{flex:1 1 150px!important;min-height:42px!important}
.ops-shell .schedule-card-actions .inline-form{display:flex!important;justify-content:flex-end!important;margin-top:10px!important}
.ops-shell .schedule-card-actions .danger.small{width:auto!important;min-width:160px!important}
@media(max-width:1280px){.ops-shell .schedule-work-card{grid-template-columns:36px 260px minmax(0,1fr)!important}.ops-shell .schedule-card-img,.ops-shell .schedule-card-noimg{width:260px!important;height:195px!important}.ops-shell .schedule-card-media .status-pill{width:260px!important}.ops-shell .schedule-card-meta{grid-template-columns:repeat(2,minmax(140px,1fr))!important}.ops-shell .schedule-card-actions .mini-form{grid-template-columns:repeat(2,minmax(0,1fr))!important}.ops-shell .schedule-card-actions .mini-form .wide{grid-column:1/-1!important}}
@media(max-width:760px){.ops-shell .schedule-work-card{grid-template-columns:28px minmax(0,1fr)!important;padding:14px 12px 14px 16px!important}.ops-shell .schedule-card-media,.ops-shell .schedule-card-main,.ops-shell .schedule-card-actions{grid-column:2!important}.ops-shell .schedule-card-img,.ops-shell .schedule-card-noimg,.ops-shell .schedule-card-media .status-pill{width:100%!important}.ops-shell .schedule-card-img,.ops-shell .schedule-card-noimg{height:auto!important;aspect-ratio:4/3!important}.ops-shell .schedule-card-meta,.ops-shell .schedule-card-actions .mini-form{grid-template-columns:1fr!important}}

/* 2026-07-09 schedule layout fix: one schedule job per row, readable cards, larger photos. */
.ops-shell .schedule-job-rows{display:grid!important;grid-template-columns:1fr!important;gap:12px!important;align-items:stretch!important}
.ops-shell .schedule-job-row{display:grid!important;grid-template-columns:52px 1.1fr 1fr 1fr 1fr auto!important;gap:10px!important;align-items:end!important;border:1px solid #cfe0f4!important;border-left:7px solid #0f766e!important;border-radius:12px!important;background:#fff!important;padding:12px!important;box-shadow:0 8px 20px rgba(15,23,42,.05)!important}
.ops-shell .schedule-job-index{height:100%!important;min-height:58px!important;width:52px!important;border-radius:10px!important;font-size:18px!important}
.ops-shell .schedule-job-row label{margin:0!important;min-width:0!important}
.ops-shell .schedule-job-row input,.ops-shell .schedule-job-row select{min-height:42px!important}
.ops-shell .schedule-job-actions{display:flex!important;gap:8px!important;align-items:center!important;justify-content:flex-end!important;flex-wrap:nowrap!important}
.ops-shell .schedule-job-actions button{min-width:82px!important;min-height:42px!important}
.ops-shell .schedule-work-card{grid-template-columns:44px 300px minmax(360px,1fr)!important;gap:22px!important;padding:20px 20px 18px 24px!important;border-radius:14px!important;border-color:#cbd5e1!important}
.ops-shell .schedule-card-img,.ops-shell .schedule-card-noimg{width:300px!important;height:220px!important;border-radius:12px!important;object-fit:cover!important}
.ops-shell .schedule-card-media .status-pill{width:300px!important;min-height:36px!important}
.ops-shell .schedule-card-actions .mini-form{grid-template-columns:170px 220px minmax(300px,1fr) 150px 130px!important;gap:12px!important}
.ops-shell .schedule-card-actions textarea{min-height:88px!important}
@media(max-width:1280px){.ops-shell .schedule-job-row{grid-template-columns:46px repeat(2,minmax(0,1fr))!important}.ops-shell .schedule-job-actions{grid-column:2/-1!important;justify-content:flex-start!important}.ops-shell .schedule-work-card{grid-template-columns:36px 240px minmax(0,1fr)!important}.ops-shell .schedule-card-img,.ops-shell .schedule-card-noimg,.ops-shell .schedule-card-media .status-pill{width:240px!important}.ops-shell .schedule-card-img,.ops-shell .schedule-card-noimg{height:180px!important}.ops-shell .schedule-card-actions .mini-form{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:760px){.ops-shell .schedule-job-row{grid-template-columns:40px minmax(0,1fr)!important}.ops-shell .schedule-job-row label,.ops-shell .schedule-job-actions{grid-column:2!important}.ops-shell .schedule-job-actions{flex-wrap:wrap!important}.ops-shell .schedule-work-card{grid-template-columns:28px minmax(0,1fr)!important}.ops-shell .schedule-card-media,.ops-shell .schedule-card-main,.ops-shell .schedule-card-actions{grid-column:2!important}.ops-shell .schedule-card-img,.ops-shell .schedule-card-noimg,.ops-shell .schedule-card-media .status-pill{width:100%!important}.ops-shell .schedule-card-img,.ops-shell .schedule-card-noimg{height:auto!important;aspect-ratio:4/3!important}.ops-shell .schedule-card-actions .mini-form{grid-template-columns:1fr!important}}

/* Schedule editor: keep every date/time control inside its card. */
.ops-shell .schedule-job-builder{min-width:0!important;max-width:100%!important;overflow:hidden!important}
.ops-shell .schedule-job-row{
  grid-template-areas:"index publish-date publish-time close-date close-time quantity actions"!important;
  grid-template-columns:52px minmax(150px,1.15fr) minmax(118px,.8fr) minmax(150px,1.15fr) minmax(118px,.8fr) minmax(78px,.55fr) minmax(250px,auto)!important;
  width:100%!important;min-width:0!important;max-width:100%!important
}
.ops-shell .schedule-job-index{grid-area:index!important}
.ops-shell .schedule-publish-date{grid-area:publish-date!important}
.ops-shell .schedule-publish-time{grid-area:publish-time!important}
.ops-shell .schedule-close-date{grid-area:close-date!important}
.ops-shell .schedule-close-time{grid-area:close-time!important}
.ops-shell .schedule-quantity{grid-area:quantity!important}
.ops-shell .schedule-job-actions{grid-area:actions!important;min-width:0!important;max-width:100%!important;flex-wrap:wrap!important}
.ops-shell .schedule-job-row label,.ops-shell .schedule-job-row input{min-width:0!important;max-width:100%!important;width:100%!important;box-sizing:border-box!important}
@media(max-width:1450px){
  .ops-shell .schedule-job-row{
    grid-template-areas:"index publish-date publish-time close-date close-time" "index quantity actions actions actions"!important;
    grid-template-columns:46px repeat(4,minmax(0,1fr))!important
  }
  .ops-shell .schedule-job-actions{justify-content:flex-start!important}
}
@media(max-width:760px){
  .ops-shell .schedule-job-row{
    grid-template-areas:"index publish-date" "index publish-time" "index close-date" "index close-time" "index quantity" "index actions"!important;
    grid-template-columns:40px minmax(0,1fr)!important;align-items:stretch!important
  }
  .ops-shell .schedule-job-index{width:40px!important;height:100%!important}
  .ops-shell .schedule-job-row label,.ops-shell .schedule-job-actions{grid-column:auto!important}
  .ops-shell .schedule-job-actions{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;width:100%!important}
  .ops-shell .schedule-job-actions button{width:100%!important;min-width:0!important}
  .ops-shell .schedule-job-actions .remove-schedule-job{grid-column:1/-1!important}
}

/* 2026-07-09 ERP workflow entry: 管家婆式常用功能與單據中心入口 */
.ops-shell .erp-action-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:14px 0 18px}
.ops-shell .erp-action-grid.compact{grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-top:10px}
.ops-shell .erp-action-card{display:block;border:1px solid #cfe0f4;border-left:6px solid #0f766e;border-radius:10px;padding:14px 16px;background:#fff;color:#0f172a;text-decoration:none;box-shadow:0 6px 18px rgba(15,23,42,.05)}
.ops-shell .erp-action-card:hover{border-color:#0f766e;background:#f0fdfa}
.ops-shell .erp-action-card b{display:block;font-size:18px;margin-bottom:6px}
.ops-shell .erp-action-card span{display:block;color:#64748b;line-height:1.5}
.document-filter-form{border:1px solid #dbeafe;background:#f8fbff;border-radius:12px;padding:14px;margin:12px 0 16px}
.document-center-table table{min-width:1180px}
.document-center-table td:nth-child(3){min-width:240px}
.document-workflow-form{border:1px solid #dbeafe;background:#f8fbff;border-radius:12px;padding:14px;margin:14px 0 18px}
.document-workflow-table table{min-width:1250px}
.workflow-actions{display:flex;gap:6px;flex-wrap:wrap}
.document-total-bar{display:flex;justify-content:flex-end;gap:18px;align-items:center;border:1px solid #dbeafe;background:#f8fbff;border-radius:10px;padding:12px;font-weight:900}
.document-total-bar b{font-size:20px;color:#0f766e}
.ops-shell .notice-block{border:1px solid #a7f3d0;background:#ecfdf5;color:#065f46;border-radius:10px;padding:12px 14px;line-height:1.6;margin-top:10px}
.inventory-scan-panel{grid-column:1/-1;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;padding:14px}
.inventory-scan-panel>span:first-child{display:block;margin-bottom:7px;font-weight:900;color:#0f172a}
.inventory-scan-panel input{font-size:20px;font-weight:800;letter-spacing:0}
.inventory-scan-rules{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.inventory-scan-rule{display:inline-flex;align-items:center;gap:6px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;padding:6px 9px;font-size:13px;font-weight:800}
.inventory-scan-rule.ok{border-color:#86efac;color:#166534}.inventory-scan-rule.zero{border-color:#fcd34d;color:#92400e}.inventory-scan-rule.missing{border-color:#fca5a5;color:#991b1b}
.inventory-scan-status{display:block;margin-top:10px;border-left:5px solid #94a3b8;background:#fff;padding:9px 11px;color:#475569;font-weight:800}
.inventory-scan-status.is-ok{border-color:#16a34a;background:#f0fdf4;color:#166534}.inventory-scan-status.is-zero{border-color:#d97706;background:#fffbeb;color:#92400e}.inventory-scan-status.is-missing{border-color:#dc2626;background:#fef2f2;color:#991b1b}
.inventory-count-lines-panel{border:1px solid #dbeafe;background:#f8fbff;border-radius:10px;padding:12px}
.inventory-count-lines-table{min-width:980px}
.inventory-count-lines-table td{vertical-align:middle}
.inventory-count-product{display:flex;flex-direction:column;gap:3px}
.inventory-count-qty{width:86px}
.inventory-count-detail-row td{background:#fbfdff}
.inventory-diff{font-weight:900;white-space:nowrap}.inventory-diff.is-match{color:#047857}.inventory-diff.is-short{color:#b91c1c}.inventory-diff.is-over{color:#b45309}
.inventory-approval-panel{display:flex;gap:8px;align-items:end;flex-wrap:wrap;min-width:310px}.inventory-approval-panel label{min-width:180px}.inventory-approval-panel input{min-width:180px}
.reminder-card.scene-inventory{grid-column:1/-1;background:linear-gradient(180deg,#fff,#fff7ed);border-color:#fed7aa}.reminder-card.scene-inventory:before{background:#ea580c}.reminder-card.scene-inventory .button-like,.reminder-card.scene-inventory .status-pill{border-color:#fed7aa;color:#c2410c;background:#fff}
.reconcile-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}.reconcile-actions{display:flex;gap:8px;flex-wrap:wrap}.reconcile-summary-table{min-width:900px}.reconcile-detail-table{min-width:1500px}.reconcile-customer{font-weight:900;color:#0f172a}.reconcile-source{font-size:12px;color:#475569}.reconcile-status{display:inline-flex;padding:4px 8px;border-radius:999px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:800;font-size:12px}.reconcile-status.paid{border-color:#86efac;background:#f0fdf4;color:#166534}.reconcile-status.partial{border-color:#fcd34d;background:#fffbeb;color:#92400e}.reconcile-status.unpaid,.reconcile-status.over{border-color:#fca5a5;background:#fef2f2;color:#991b1b}.reconcile-section-title{margin:22px 0 8px}.reconcile-print-title{display:none}
.customer-document-picker{grid-column:1/-1;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;padding:12px}.customer-document-picker h3{margin:0 0 8px;font-size:16px}.customer-document-list{display:grid;gap:7px}.customer-document-option{display:grid;grid-template-columns:auto minmax(120px,.6fr) minmax(220px,1.4fr) repeat(3,minmax(90px,.45fr));gap:10px;align-items:center;border:1px solid #d8e0ea;border-radius:7px;background:#fff;padding:9px 10px}.customer-document-option input{width:18px;height:18px}.customer-document-option small{color:#64748b}.customer-document-option strong:last-child{color:#b91c1c}.customer-document-empty{padding:14px;border:1px dashed #cbd5e1;border-radius:7px;background:#fff;color:#64748b}.billing-request-card{border:1px solid #d8e0ea;border-radius:8px;margin:12px 0;background:#fff;overflow:hidden}.billing-request-card summary{cursor:pointer;display:flex;justify-content:space-between;gap:12px;padding:13px 15px;background:#f8fafc;font-weight:900}.billing-request-meta{display:flex;gap:12px;flex-wrap:wrap}.billing-request-body{padding:14px}.billing-request-table{min-width:1100px}
.bad-debt-card{border:1px solid #fecaca;border-left:5px solid #dc2626;border-radius:8px;margin:12px 0;background:#fff;overflow:hidden}.bad-debt-card summary{cursor:pointer;display:flex;justify-content:space-between;gap:12px;padding:13px 15px;background:#fff7f7;font-weight:900}.bad-debt-body{padding:14px}.bad-debt-followups{min-width:900px}.bad-debt-amount{color:#b91c1c;font-weight:900}
@media(max-width:900px){.customer-document-option{grid-template-columns:auto 1fr}.customer-document-option span,.customer-document-option strong{grid-column:2}}
@media(max-width:820px){.reconcile-head{display:block}.reconcile-actions{margin-top:10px}.reconcile-actions button,.reconcile-actions a{flex:1 1 140px}}
@media print{body.reconcile-print .ops-nav,body.reconcile-print .ops-head,body.reconcile-print .mobile-ops-nav,body.reconcile-print .reconcile-filter,body.reconcile-print .reconcile-actions,body.reconcile-print .floating-mobile-actions{display:none!important}body.reconcile-print .ops-shell{display:block;background:#fff}body.reconcile-print .ops-wrap{max-width:none;padding:0}body.reconcile-print .ops-tab{display:none!important}body.reconcile-print #finance-reconcile{display:block!important;border:0;box-shadow:none;margin:0;padding:0}body.reconcile-print .reconcile-print-title{display:block;margin-bottom:14px}body.reconcile-print .table-wrap{overflow:visible}body.reconcile-print table{font-size:10px}body.reconcile-print th,body.reconcile-print td{padding:5px 6px}body.reconcile-print .button-like{display:none!important}@page{size:A4 landscape;margin:9mm}}
@media print{body.billing-print .ops-nav,body.billing-print .ops-head,body.billing-print .mobile-ops-nav,body.billing-print .billing-create-form,body.billing-print .billing-request-card:not(.is-print-target),body.billing-print .billing-request-actions,body.billing-print .floating-mobile-actions{display:none!important}body.billing-print .ops-shell{display:block;background:#fff}body.billing-print .ops-wrap{max-width:none;padding:0}body.billing-print .ops-tab{display:none!important}body.billing-print #finance-request{display:block!important;border:0;box-shadow:none;margin:0;padding:0}body.billing-print .billing-request-card.is-print-target{display:block!important;border:0}body.billing-print .billing-request-card.is-print-target summary{background:#fff;padding:0 0 12px}body.billing-print .billing-request-body{padding:0}body.billing-print .table-wrap{overflow:visible}@page{size:A4 landscape;margin:9mm}}
@media print{body.document-print .ops-nav,body.document-print .ops-head,body.document-print .mobile-ops-nav,body.document-print .document-filter-form,body.document-print .erp-action-grid,body.document-print .pager,body.document-print .floating-mobile-actions{display:none!important}body.document-print .ops-shell{display:block;background:#fff}body.document-print .ops-wrap{max-width:none;padding:0}body.document-print .ops-tab{display:none!important}body.document-print #document-center{display:block!important;border:0;box-shadow:none;margin:0;padding:0}body.document-print .table-wrap{overflow:visible}body.document-print table{font-size:10px}body.document-print th,body.document-print td{padding:5px 6px}@page{size:A4 landscape;margin:9mm}}

/* BAOHUI_MOBILE_OPS_LAYOUT_20260711_START */
@media (max-width: 820px) {
  html, body {
    width: 100% !important;
    max-width: 100% !important;
    height: auto !important;
    overflow: auto !important;
    overflow-x: hidden !important;
  }
  .ops-shell {
    display: block !important;
    grid-template-columns: 1fr !important;
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    height: auto !important;
    overflow: visible !important;
  }
  .ops-main {
    height: auto !important;
    overflow: visible !important;
  }
  .ops-nav {
    position: sticky !important;
    top: 0 !important;
    z-index: 80 !important;
    width: 100% !important;
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    padding: 10px 10px 12px !important;
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 8px !important;
    overflow-x: auto !important;
    overflow-y: hidden !important;
    white-space: nowrap !important;
    border-radius: 0 !important;
    -webkit-overflow-scrolling: touch !important;
    scrollbar-gutter: auto !important;
  }
  .ops-nav strong {
    flex: 0 0 auto !important;
    padding: 8px 10px !important;
    font-size: 16px !important;
  }
  .ops-nav-group {
    display: contents !important;
    border: 0 !important;
    margin: 0 !important;
    background: transparent !important;
  }
  .ops-nav-group summary,
  .ops-nav-current-label {
    display: none !important;
  }
  .ops-nav-sub {
    display: contents !important;
    padding: 0 !important;
    gap: 0 !important;
  }
  .ops-nav a,
  .ops-nav-sub a {
    flex: 0 0 auto !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: auto !important;
    min-width: max-content !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 10px 12px !important;
    border-radius: 999px !important;
    font-size: 14px !important;
    line-height: 1.2 !important;
    box-shadow: none !important;
    transform: none !important;
  }
  .ops-nav a.active,
  .ops-nav-sub a.active {
    background: #0f766e !important;
    color: #fff !important;
    box-shadow: 0 0 0 2px rgba(250, 204, 21, .75) inset !important;
  }
  .ops-wrap {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    padding: 14px 12px 90px !important;
    overflow-x: hidden !important;
  }
  .ops-head {
    display: block !important;
    margin-bottom: 12px !important;
  }
  .ops-head h1 {
    font-size: 24px !important;
    line-height: 1.25 !important;
  }
  .ops-card,
  .ops-tab,
  .ops-card.ops-tab {
    width: 100% !important;
    max-width: 100% !important;
    min-width: 0 !important;
    padding: 14px !important;
    overflow: visible !important;
  }
  .ops-card h2 {
    font-size: 22px !important;
    line-height: 1.25 !important;
  }
  .form-grid,
  .erp-grid,
  .warehouse-form-grid,
  .category-grid,
  .color-module-grid,
  .schedule-work-grid,
  .schedule-form-grid,
  .settle-grid,
  .member-workbench,
  .member-search-panel,
  .order-actions,
  .payment-inputs,
  .payment-offset-head,
  .logistics-form-grid,
  .finance-grid,
  .metrics-grid,
  .dashboard-grid,
  .report-grid {
    display: grid !important;
    grid-template-columns: 1fr !important;
    gap: 12px !important;
  }
  .ops-shell input,
  .ops-shell select,
  .ops-shell textarea,
  .ops-shell button {
    max-width: 100% !important;
    min-width: 0 !important;
    font-size: 16px !important;
  }
  .ops-shell input:not([type="checkbox"]):not([type="radio"]),
  .ops-shell select,
  .ops-shell textarea {
    width: 100% !important;
    box-sizing: border-box !important;
  }
  .ops-shell textarea {
    min-height: 120px !important;
  }
  .ops-shell .button,
  .ops-shell .button-like,
  .ops-shell button {
    min-height: 44px !important;
    white-space: normal !important;
  }
  .table-wrap,
  .ops-table-wrap {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch !important;
  }
  .table-wrap table,
  .ops-table-wrap table {
    min-width: 720px !important;
  }
  .schedule-card,
  .product-row-card,
  .stock-result-card {
    display: grid !important;
    grid-template-columns: 92px minmax(0, 1fr) !important;
    gap: 12px !important;
    align-items: start !important;
  }
  .schedule-card-img,
  .product-thumb,
  .stock-thumb {
    width: 88px !important;
    height: 88px !important;
    object-fit: cover !important;
  }
  .schedule-card-actions {
    grid-column: 1 / -1 !important;
    display: grid !important;
    grid-template-columns: 1fr !important;
    gap: 10px !important;
  }
}
/* BAOHUI_MOBILE_OPS_LAYOUT_20260711_END */


/* BAOHUI_MOBILE_DROPDOWN_NAV_20260711_START */
.ops-tab { display: none !important; }
.ops-tab.is-active { display: block !important; }
.metric-grid.ops-tab.is-active { display: grid !important; }
body:has(.ops-tab:target) .ops-tab.is-active { display: none !important; }
body:has(.ops-tab:target) .ops-tab:target { display: block !important; }
body:has(.ops-tab:target) .metric-grid.ops-tab:target { display: grid !important; }

.ops-mobile-nav-toggle {
  display: none;
}
@media (max-width: 820px) {
  .ops-mobile-nav-toggle {
    position: sticky !important;
    top: 0 !important;
    z-index: 120 !important;
    width: 100% !important;
    min-height: 52px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 10px !important;
    padding: 12px 14px !important;
    border: 0 !important;
    border-bottom: 1px solid rgba(255,255,255,.12) !important;
    background: #101a17 !important;
    color: #fff !important;
    font-size: 16px !important;
    font-weight: 900 !important;
    text-align: left !important;
  }
  .ops-mobile-nav-toggle span::before {
    content: "☰";
    display: inline-block;
    margin-right: 8px;
    color: #facc15;
  }
  .ops-mobile-nav-toggle b {
    min-width: 0 !important;
    max-width: 58vw !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
    padding: 6px 9px !important;
    border-radius: 999px !important;
    background: #0f766e !important;
    color: #fff !important;
    font-size: 13px !important;
    font-weight: 900 !important;
  }
  .ops-mobile-nav-toggle[aria-expanded="true"] span::before {
    content: "×";
    font-size: 20px;
    line-height: 0;
  }
  .ops-shell .ops-nav {
    display: none !important;
    position: sticky !important;
    top: 52px !important;
    z-index: 110 !important;
    width: 100% !important;
    max-height: 72vh !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding: 12px !important;
    border-radius: 0 0 12px 12px !important;
    background: #18201d !important;
    box-shadow: 0 18px 36px rgba(0,0,0,.28) !important;
    white-space: normal !important;
  }
  .ops-shell.ops-mobile-menu-open .ops-nav {
    display: block !important;
  }
  .ops-shell .ops-nav strong {
    display: block !important;
    padding: 8px 10px 12px !important;
    font-size: 18px !important;
  }
  .ops-shell .ops-nav-group {
    display: block !important;
    border: 1px solid rgba(255,255,255,.10) !important;
    border-radius: 10px !important;
    margin: 8px 0 !important;
    background: rgba(255,255,255,.04) !important;
  }
  .ops-shell .ops-nav-group summary {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 12px !important;
    color: #fff !important;
    font-weight: 900 !important;
  }
  .ops-shell .ops-nav-current-label {
    display: block !important;
    margin: 0 10px 8px !important;
  }
  .ops-shell .ops-nav-sub {
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
    padding: 0 8px 10px !important;
  }
  .ops-shell .ops-nav a,
  .ops-shell .ops-nav-sub a {
    display: flex !important;
    width: 100% !important;
    justify-content: flex-start !important;
    min-height: 42px !important;
    padding: 10px 12px !important;
    border-radius: 8px !important;
    white-space: normal !important;
    box-shadow: none !important;
    transform: none !important;
  }
  .ops-wrap {
    padding-top: 12px !important;
  }
}
/* BAOHUI_MOBILE_DROPDOWN_NAV_20260711_END */

/* BAOHUI_MOBILE_DROPDOWN_NAV_CLICKFIX_CSS_20260711_START */
@media (max-width: 820px) {
  .ops-mobile-nav-toggle {
    pointer-events: auto !important;
    cursor: pointer !important;
    touch-action: manipulation !important;
    z-index: 60 !important;
  }
  .ops-shell.ops-mobile-menu-open .ops-nav,
  body.ops-mobile-menu-open .ops-shell .ops-nav {
    display: block !important;
  }
}
/* BAOHUI_MOBILE_DROPDOWN_NAV_CLICKFIX_CSS_20260711_END */


.category-bucket-list{display:grid;gap:12px;margin:14px 0}.category-bucket-card{border:1px solid #d8e0ea;border-radius:12px;background:#fff;overflow:hidden}.category-bucket-card.dragging{opacity:.56;border-color:#2563eb;box-shadow:0 8px 24px rgba(37,99,235,.18)}.category-bucket-card summary{cursor:pointer;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;padding:14px 16px;background:#f8fafc;list-style:none}.category-bucket-card summary::-webkit-details-marker{display:none}.category-bucket-card summary b{display:block;font-size:17px;color:#0f172a}.category-bucket-card summary small{display:block;margin-top:4px;color:#64748b}.category-drag-handle{display:inline-flex;align-items:center;margin-right:8px;color:#64748b;cursor:grab;font-size:20px;vertical-align:middle}.category-bucket-count{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:7px 10px;font-weight:900}.category-bucket-inner{padding:14px 16px;border-top:1px solid #e2e8f0}.category-bucket-inner h3{margin:8px 0 10px}.category-order-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 14px;padding:10px 12px;border:1px solid #cbd5e1;background:#f8fafc;border-radius:8px}.category-order-tools b{margin-right:auto}.category-bucket-products{max-height:520px;overflow:auto;border:1px solid #e2e8f0;border-radius:10px}.category-bucket-products table{margin:0;min-width:1600px}.category-bucket-products td input:not([type="checkbox"]){width:100%;min-width:118px;padding:7px 8px}.category-bucket-products .category-sort-input{min-width:74px!important;width:74px!important}.category-bucket-products .inline-actions{flex-wrap:nowrap}.category-move-control{display:flex;align-items:center;gap:7px;min-width:260px}.category-move-control select{min-width:190px;padding:7px 8px}@media(max-width:760px){.category-bucket-card summary{grid-template-columns:1fr}.category-bucket-count{width:max-content}.category-order-tools b{width:100%}.category-bucket-products{max-height:none}}
.category-bucket-rename-form{display:grid;grid-template-columns:minmax(180px,1fr) minmax(220px,1fr) auto;gap:10px;align-items:end;margin:0 0 16px;padding:12px;border:1px solid #bfdbfe;border-radius:8px;background:#eff6ff}.category-bucket-rename-form label{margin:0}.category-bucket-rename-form .muted{grid-column:1/-1}@media(max-width:760px){.category-bucket-rename-form{grid-template-columns:1fr}.category-bucket-rename-form button{width:100%}}
.fixed-expense-list{display:grid;gap:8px;margin:12px 0 22px}.fixed-expense-editor{border:1px solid #d8e0ea;border-radius:8px;background:#fff;overflow:hidden}.fixed-expense-editor>summary{cursor:pointer;list-style:none;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:center;padding:12px 14px;background:#f8fafc}.fixed-expense-editor>summary::-webkit-details-marker{display:none}.fixed-expense-editor>summary b,.fixed-expense-editor>summary small{display:block}.fixed-expense-editor>summary small{margin-top:4px;color:#64748b}.fixed-expense-edit-form{margin:0;padding:14px;border-top:1px solid #e2e8f0}.fixed-expense-delete{padding:0 14px 14px}.fixed-expense-payment-head{align-items:end;margin-top:20px}.fixed-expense-payment-head h3{margin-bottom:4px}.fixed-expense-filter{margin:12px 0}.fixed-expense-payment-table{min-width:1250px}.fixed-expense-payment-table td small{display:block;margin-top:4px;color:#64748b}.fixed-expense-overdue{background:#fff1f2}.fixed-payment-editor{min-width:110px}.fixed-payment-editor>summary{cursor:pointer;color:#0f766e;font-weight:800}.fixed-payment-editor .product-form{min-width:680px;margin:8px 0;padding:12px;border:1px solid #d8e0ea;border-radius:8px;background:#fff}.fixed-payment-editor .inline-form{margin-top:8px}@media(max-width:760px){.fixed-expense-editor>summary{grid-template-columns:1fr}.fixed-expense-payment-head{display:block}.fixed-expense-payment-head form{margin-top:10px}.fixed-payment-editor .product-form{min-width:560px}}
.fixed-asset-filter{margin:16px 0}.fixed-asset-table{min-width:1550px}.fixed-asset-table td small{display:block;margin-top:4px;color:#64748b}.fixed-asset-attention{background:#fff7ed}.fixed-asset-editor{min-width:110px}.fixed-asset-editor>summary{cursor:pointer;color:#0f766e;font-weight:800}.fixed-asset-editor .product-form{min-width:780px;margin:8px 0;padding:12px;border:1px solid #d8e0ea;border-radius:8px;background:#fff}.fixed-asset-editor .inline-form{margin-top:8px}@media(max-width:760px){.fixed-asset-editor .product-form{min-width:620px}}
.mobile-asset-workbench{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:16px 0 22px}.mobile-asset-panel{border:1px solid #d8e0ea;border-radius:8px;padding:14px;background:#f8fafc}.mobile-asset-panel h3{margin-top:0}.mobile-asset-panel .product-form{margin-bottom:0}.mobile-asset-filter{margin:12px 0}.mobile-asset-table{min-width:1450px}.mobile-movement-table{min-width:1450px}.mobile-asset-table td small,.mobile-movement-table td small{display:block;margin-top:4px;color:#64748b}.mobile-asset-overdue{background:#fff1f2}.mobile-asset-attention{background:#fff7ed}.mobile-asset-editor>summary{cursor:pointer;color:#0f766e;font-weight:800}.mobile-asset-editor .product-form{min-width:720px;margin:8px 0;padding:12px;border:1px solid #d8e0ea;border-radius:8px;background:#fff}.mobile-asset-editor .inline-form{margin-top:8px}@media(max-width:900px){.mobile-asset-workbench{grid-template-columns:1fr}}@media(max-width:760px){.mobile-asset-editor .product-form{min-width:600px}}
.post-reply-editor{margin:0 0 22px;padding:14px;border:1px solid #d8e0ea;border-radius:12px;background:#f8fafc}
.post-reply-set-list{display:grid;gap:14px;margin:18px 0}
.post-reply-set-card{border:1px solid #d8e0ea;border-radius:12px;background:#fff;overflow:hidden}
.post-reply-set-card>summary{cursor:pointer;list-style:none;padding:14px 16px;background:#f8fafc}
.post-reply-set-card>summary::-webkit-details-marker{display:none}
.post-reply-set-card>summary b{display:block;font-size:17px;color:#0f172a}
.post-reply-set-card>summary small{display:block;margin-top:4px;color:#64748b}
.post-reply-set-body{padding:14px 16px;border-top:1px solid #e2e8f0}
.post-reply-qa-row{display:grid;grid-template-columns:minmax(180px,1fr) minmax(180px,1fr) auto;gap:10px;align-items:end;padding:10px 0;border-top:1px dashed #e2e8f0}
.post-reply-qa-row .wide{grid-column:1/-1}
.post-reply-qa-add{margin-top:8px}
.post-reply-set-tools{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.post-reply-preview-box textarea,.post-reply-matcher textarea,.schedule-qa-draft{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;line-height:1.45}
.post-reply-matcher{margin-top:22px;padding:14px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff}
.post-reply-match-hits{display:grid;gap:10px;margin-top:12px}
.post-reply-hit{border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;background:#fff}
.post-reply-hit b{display:block;margin-bottom:6px;color:#0f172a}
.post-reply-hit pre{white-space:pre-wrap;margin:0;font:inherit}
@media(max-width:760px){.post-reply-qa-row{grid-template-columns:1fr}.post-reply-set-tools form,.post-reply-set-tools button{width:100%}}
.fb-daily-compare{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin:14px 0}
.fb-daily-compare .metric b.warn{color:#b45309}.fb-daily-compare .metric b.bad{color:#b91c1c}.fb-daily-compare .metric b.ok{color:#047857}
.fb-daily-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:12px 0 16px}
.fb-daily-card{border:1px solid #d8e0ea;border-radius:12px;background:#fff;padding:14px;margin:12px 0}
.fb-daily-card.is-todo{border-color:#f59e0b;background:#fffbeb}
.fb-daily-card.is-done{border-color:#a7f3d0;background:#f0fdf4}
.fb-daily-card.is-close{border-color:#fda4af;background:#fff1f2}
.fb-daily-head{display:grid;grid-template-columns:72px minmax(0,1fr) auto;gap:12px;align-items:start}
.fb-daily-head img,.fb-daily-noimg{width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid #cbd5e1;background:#f8fafc;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-weight:800}
.fb-daily-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
.fb-daily-copybox textarea{width:100%;min-height:90px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;line-height:1.4}
.fb-daily-playbook{width:100%;min-height:220px;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;line-height:1.45}
.fb-daily-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
@media(max-width:760px){.fb-daily-head{grid-template-columns:1fr}}


</style>
<script>
/* BAOHUI_LOCK_MOBILE_ZOOM_20260711_START */
(function() {
  var locked = false;
  function preventZoom(event) {
    if (event && event.preventDefault) event.preventDefault();
  }
  function lockMobileZoom() {
    if (locked) return;
    locked = true;
    document.addEventListener('gesturestart', preventZoom, { passive: false });
    document.addEventListener('gesturechange', preventZoom, { passive: false });
    document.addEventListener('gestureend', preventZoom, { passive: false });
    document.addEventListener('touchmove', function(event) {
      if (event.scale && event.scale !== 1) preventZoom(event);
    }, { passive: false });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', lockMobileZoom);
  else lockMobileZoom();
})();
/* BAOHUI_LOCK_MOBILE_ZOOM_20260711_END */
</script>
</head>
<body<?= !empty($isEmbed) ? ' class="is-embed"' : '' ?><?= (($opsInitialTab ?? '') === 'customer-shipping') ? ' data-ops-open-shipping="1"' : '' ?><?= (($opsInitialTab ?? '') !== '' && ($opsInitialTab ?? '') !== 'customer-shipping') ? ' data-ops-open-tab="' . h($opsInitialTab) . '"' : '' ?>>
<div class="ops-shell">
<button type="button" class="ops-mobile-nav-toggle" onclick="return window.toggleOpsMobileMenu ? window.toggleOpsMobileMenu(event) : (function(btn,ev){if(ev){ev.preventDefault();ev.stopPropagation();}var shell=document.querySelector('.ops-shell');if(!shell)return false;var open=!shell.classList.contains('ops-mobile-menu-open');shell.classList.toggle('ops-mobile-menu-open',open);document.body.classList.toggle('ops-mobile-menu-open',open);btn.setAttribute('aria-expanded',open?'true':'false');return false;})(this,event);" aria-expanded="false" aria-controls="opsMobileNav"><span>功能選單</span><b data-ops-mobile-current>總覽</b></button>
<aside id="opsMobileNav" class="ops-nav" aria-label="電商營運管理功能">
  <strong>電商營運</strong>
  <a href="#overview" data-tab-link="overview" class="active">總覽</a>
  <?php foreach ($opsFunctionGroups as $groupTitle => $items): ?>
    <?php $groupItems = $items; unset($groupItems['overview']); if (!$groupItems) continue; ?>
    <details class="ops-nav-group" open>
      <summary><?=h($groupTitle)?></summary>
      <div class="ops-nav-sub">
        <?php foreach ($groupItems as $tabId => $label): ?>
          <a href="#<?=h($tabId)?>" data-tab-link="<?=h($tabId)?>"><?=h($label)?></a>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endforeach; ?>
</aside>


<script>
(function () {
  if (window.__baohuiOpsNavBound20260818) return;
  window.__baohuiOpsNavBound20260818 = true;
  window.oneDollarOpsNavReady20260703 = true;
  var tabAliases = { stock: 'stock-search', analytics: 'finance-analytics', shipping: 'customer-shipping' };
  function markOpsNavCurrent(target){
    document.querySelectorAll('.ops-nav-current-label').forEach(function(old){ old.remove(); });
    document.querySelectorAll('.ops-nav-group').forEach(function(group){
      var link = group.querySelector('[data-tab-link="' + target + '"]');
      group.classList.toggle('is-current', !!link);
      if (link) {
        group.open = true;
        var summary = group.querySelector('summary');
        if (summary) {
          var badge = document.createElement('span');
          badge.className = 'ops-nav-current-label';
          badge.textContent = '目前位置：' + (link.textContent || '').trim();
          summary.insertAdjacentElement('afterend', badge);
        }
      }
    });
  }
  function resolveOpsTab(id) {
    var wanted = tabAliases[id] || String(id || '').replace(/^#/, '') || 'overview';
    var el = document.getElementById(wanted);
    if (el && el.classList.contains('ops-tab')) return wanted;
    if (el) {
      var wrap = el.closest('.ops-tab');
      if (wrap && wrap.id) return wrap.id;
    }
    return document.getElementById('overview') ? 'overview' : wanted;
  }
  function openOpsTab(id) {
    var target = resolveOpsTab(id);
    document.querySelectorAll('.ops-tab').forEach(function (tab) {
      var active = tab.id === target;
      tab.classList.toggle('is-active', active);
      tab.removeAttribute('hidden');
      tab.hidden = false;
      tab.style.setProperty('display', active ? (tab.classList.contains('metric-grid') ? 'grid' : 'block') : 'none', 'important');
    });
    document.querySelectorAll('[data-tab-link]').forEach(function (link) {
      link.classList.toggle('active', link.getAttribute('data-tab-link') === target);
      link.removeAttribute('aria-disabled');
      link.style.pointerEvents = 'auto';
    });
    markOpsNavCurrent(target);
    var mainPane = document.querySelector('.ops-main');
    if (mainPane) mainPane.scrollTop = 0;
    var nextHash = '#' + target;
    if (location.hash !== nextHash && String(location.pathname || '').indexOf('operations.php') !== -1) {
      try { history.replaceState(null, '', location.pathname + location.search + nextHash); }
      catch (err) {}
    }
    if (target === 'quotations' && typeof window.loadEmbeddedQuotation === 'function') {
      window.loadEmbeddedQuotation(false);
    }
    return target;
  }
  function tabIdFromLink(link) {
    var id = link.getAttribute('data-tab-link') || link.getAttribute('data-jump-tab') || '';
    if (id) return id;
    var href = link.getAttribute('href') || '';
    if (href.charAt(0) === '#' && href.length > 1) return decodeURIComponent(href.slice(1).split('?')[0]);
    return '';
  }
  function initialOpsTab() {
    var params = new URLSearchParams(location.search);
    var shippingKeys = ['edit_delivery','ship_q','ship_customer','ship_phone','ship_no','ship_status','ship_from','ship_to'];
    var focusShipping = document.body.getAttribute('data-ops-open-shipping') === '1';
    for (var i = 0; i < shippingKeys.length; i++) if (params.has(shippingKeys[i])) focusShipping = true;
    if (focusShipping) return 'customer-shipping';
    if (params.has('edit_product')) return 'products';
    var postedTab = document.body.getAttribute('data-ops-open-tab') || '';
    if (postedTab) return tabAliases[postedTab] || postedTab;
    var qTab = params.get('tab') || '';
    if (qTab) return tabAliases[qTab] || qTab;
    return (location.hash || '#overview').replace(/^#/, '') || 'overview';
  }
  document.addEventListener('click', function (event) {
    var link = event.target.closest ? event.target.closest('[data-tab-link], [data-jump-tab], a.erp-action-card, .ops-nav a[href^="#"]') : null;
    if (!link) return;
    var id = tabIdFromLink(link);
    if (!id) return;
    event.preventDefault();
    openOpsTab(id);
  });
  window.openOpsTab = openOpsTab;
  window.showOpsTab = openOpsTab;
  window.addEventListener('hashchange', function () { openOpsTab((location.hash || '#overview').slice(1)); });
  function bootOpsNav() { openOpsTab(initialOpsTab()); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootOpsNav);
  else bootOpsNav();
})();
</script>

<div class="ops-main">
<main class="ops-wrap">
  <div class="ops-head">
    <div>
      <h1>電商營運管理</h1>
      <p class="muted">這裡是正式的寶輝電腦部營運後台：產品、庫存、排程上架、截標、得標結算、記單出貨與會員資料都在這裡作業。</p>
    </div>
    <div id="opsSyncStatus" class="ops-sync-pill">最後同步：尚未同步｜每 1 分鐘更新</div>
  </div>
  <?php if ($notice): ?><div class="alert success"><?=h($notice)?></div><?php endif; ?>

  
  <section class="ops-tab is-active" id="overview">
    <?php
      $opsStockTotal = 0;
      $opsReservedTotal = 0;
      $opsSoldTotal = 0;
      $opsAvailableTotal = 0;
      $opsStockCostValue = 0;
      $opsProductMap = [];
      foreach ($products as $p) {
          $pid = (string)($p['id'] ?? $p['product_id'] ?? '');
          if ($pid !== '') $opsProductMap[$pid] = $p;
          $stockTotal = (int)($p['stock_total'] ?? $p['stock'] ?? 0);
          $reserved = stock_reserved_total($p);
          $sold = (int)($p['stock_sold'] ?? $p['sold'] ?? 0);
          $available = max(0, $stockTotal - $reserved - $sold);
          $cost = (float)($p['cost'] ?? 0);
          $opsStockTotal += $stockTotal;
          $opsReservedTotal += $reserved;
          $opsSoldTotal += $sold;
          $opsAvailableTotal += $available;
          $opsStockCostValue += $available * $cost;
      }

      $opsFinanceAll = $financeAnalytics['all'] ?? ops_erp_finance_empty_row('總計', '1900-01-01', date('Y-m-d'));
      $opsFinanceSummary = [
          'revenue' => (float)($opsFinanceAll['sales_revenue'] ?? 0),
          'cost' => (float)($opsFinanceAll['sales_cost'] ?? 0),
          'profit' => (float)($opsFinanceAll['gross_profit'] ?? 0),
          'stock_in' => (float)($opsFinanceAll['stock_in_qty'] ?? 0),
          'stock_out' => (float)($opsFinanceAll['sales_qty'] ?? 0),
          'purchase_return' => (float)($opsFinanceAll['purchase_return_amount'] ?? 0),
          'sales_return' => (float)($opsFinanceAll['sales_return_amount'] ?? 0),
          'expense' => 0,
      ];

      $opsProfitRows = [];
      $opsLossRows = [];
      foreach ($stockMovements as $mv) {
          if (ops_is_payment_import_row($mv)) continue;
          if (ops_document_flow_type($mv) !== 'sales_out') continue;
          $qty = abs((float)($mv['qty'] ?? 0));
          $revenue = ops_money_amount($mv, $qty * (float)($mv['unit_price'] ?? 0));
          $cost = $qty * (float)($mv['unit_cost'] ?? 0);
          if ($revenue <= 0 && $cost <= 0) continue;
          $profit = $revenue - $cost;
          $row = [
              'product_id' => (string)($mv['product_id'] ?? ''),
              'title' => (string)($mv['product_title'] ?? ''),
              'revenue' => $revenue,
              'cost' => $cost,
              'profit' => $profit,
          ];
          if ($profit >= 0) $opsProfitRows[] = $row;
          else $opsLossRows[] = $row;
      }
      foreach ($schedules as $s) {
          $pid = (string)($s['product_id'] ?? $s['id'] ?? '');
          $product = $opsProductMap[$pid] ?? [];
          $qty = max(1, (int)($s['quantity'] ?? 1));
          $revenue = (float)($s['winning_amount'] ?? $s['bid_amount'] ?? $s['amount'] ?? 0) * $qty;
          $cost = (float)($s['product_cost'] ?? $s['cost'] ?? ($product['cost'] ?? 0)) * $qty;
          if ($revenue <= 0 && $cost <= 0) continue;
          $profit = $revenue - $cost;
          $row = [
              'product_id' => $pid,
              'title' => (string)($s['product_title'] ?? $product['title'] ?? ''),
              'revenue' => $revenue,
              'cost' => $cost,
              'profit' => $profit,
          ];
          if ($profit >= 0) $opsProfitRows[] = $row;
          else $opsLossRows[] = $row;
      }
      usort($opsProfitRows, fn($a, $b) => $b['profit'] <=> $a['profit']);
      usort($opsLossRows, fn($a, $b) => $a['profit'] <=> $b['profit']);
      $inventoryDifferenceReminders = [];
      foreach (array_reverse($inventoryCounts) as $countDoc) {
          if (in_array(($countDoc['status'] ?? ''), ['已確認並調整', '退回重盤'], true)) continue;
          $differenceLines = array_values(array_filter($countDoc['lines'] ?? [], function($line) {
              return (int)($line['diff_qty'] ?? 0) !== 0;
          }));
          if (!$differenceLines) continue;
          $countDoc['difference_lines'] = $differenceLines;
          $inventoryDifferenceReminders[] = $countDoc;
      }
    ?>

    <div class="overview-metrics">
      <div class="metric scene-metric scene-schedule"><span>待上架排程</span><strong><?=h(count($scheduleUpcoming))?> 筆</strong></div>
      <div class="metric scene-metric scene-close"><span>24 小時內截標</span><strong><?=h(count($scheduleClosingSoon))?> 筆</strong></div>
      <div class="metric scene-metric scene-ship"><span>未出貨客戶</span><strong><?=h(count($unshippedByBuyer))?> 位</strong></div>
      <div class="metric scene-metric scene-relist"><span>棄標 / 取消可重上架</span><strong><?=h(count($relistReminders))?> 筆</strong></div>
      <div class="metric scene-metric scene-schedule"><span>今日臉書待發文</span><strong><?=h((int)($facebookDailyCompare['missing_post'] ?? 0))?> 筆</strong></div>
    </div>

    <div class="overview-board">
      <div class="ops-card reminder-card scene-schedule">
        <div class="section-head"><h2>排程上架提醒</h2>
          <div>
            <a class="button-like small" href="#facebook-daily" data-jump-tab="facebook-daily">今日臉書日報</a>
            <a class="button-like small" href="#schedule" data-jump-tab="schedule">看排程</a>
          </div>
        </div>
        <?php if (!$scheduleUpcoming): ?><p class="muted">目前 3 天內沒有待上架排程。</p><?php endif; ?>
        <?php foreach(array_slice($scheduleUpcoming, 0, 10) as $s): ?>
          <div class="reminder-row">
            <div><b><?=h($s['product_id'] ?? '')?></b> <?=h($s['product_title'] ?? '')?><div class="small text-muted">預定：<?=h($s['scheduled_publish_at'] ?? $s['publish_at'] ?? '')?>　數量：<?=h($s['quantity'] ?? 1)?></div></div>
            <span class="status-pill"><?=h($s['publish_status'] ?? $s['status'] ?? '未上架')?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="ops-card reminder-card scene-close">
        <div class="section-head"><h2>截標提醒</h2><a class="button-like small" href="#schedule" data-jump-tab="schedule">看未結標</a></div>
        <?php if (!$scheduleClosingSoon): ?><p class="muted">目前 24 小時內沒有截標提醒。</p><?php endif; ?>
        <?php foreach(array_slice($scheduleClosingSoon, 0, 10) as $s): ?>
          <div class="reminder-row">
            <div><b><?=h($s['product_id'] ?? '')?></b> <?=h($s['product_title'] ?? '')?><div class="small text-muted">截標：<?=h($s['close_at'] ?? '')?>　上架：<?=h($s['publish_at'] ?? '')?></div></div>
            <span class="status-pill"><?=h($s['order_status'] ?? '未結標')?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="ops-card reminder-card scene-ship">
        <div class="section-head"><h2>未出貨客戶</h2><a class="button-like small" href="#orders" data-jump-tab="orders">去記單出貨</a></div>
        <?php if (!$unshippedByBuyer): ?><p class="muted">目前沒有未出貨客戶。</p><?php endif; ?>
        <?php foreach(array_slice($unshippedByBuyer, 0, 10) as $g): ?>
          <div class="reminder-row">
            <div><b><?=h($g['buyer']['name'] ?: ($g['buyer']['facebook'] ?: $g['buyer']['phone']))?></b><div class="small text-muted">品項 <?=h(count($g['items']))?> 筆　應收 <?=money($g['receivable'])?>　未收 <?=money($g['unpaid'])?></div></div>
            <span class="status-pill">未出貨</span>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="ops-card reminder-card scene-relist">
        <div class="section-head"><h2>棄標 / 取消重上架</h2><a class="button-like small" href="#schedule" data-jump-tab="schedule">補上架</a></div>
        <?php if (!$relistReminders): ?><p class="muted">目前沒有棄標或取消待重上架品項。</p><?php endif; ?>
        <?php foreach(array_slice($relistReminders, 0, 10) as $s): ?>
          <div class="reminder-row">
            <div><b><?=h($s['product_id'] ?? '')?></b> <?=h($s['product_title'] ?? '')?><div class="small text-muted">原得標者：<?=h($s['winner'] ?? '-')?>　結標：<?=h($s['close_at'] ?? '')?></div></div>
            <span class="status-pill"><?=h($s['order_status'] ?? '棄標')?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="ops-card reminder-card scene-inventory">
        <div class="section-head"><h2>盤點差異待管理者確認</h2><a class="button-like small" href="#inventory-count" data-jump-tab="inventory-count">前往確認</a></div>
        <?php if (!$inventoryDifferenceReminders): ?><p class="muted">目前沒有待確認的盤點差異。</p><?php endif; ?>
        <?php foreach(array_slice($inventoryDifferenceReminders, 0, 10) as $countDoc): $differenceLines = $countDoc['difference_lines'] ?? []; ?>
          <div class="reminder-row">
            <div>
              <b><?=h($countDoc['doc_no'] ?? '')?></b>　<?=h($countDoc['warehouse_name'] ?? '')?>
              <?php foreach(array_slice($differenceLines, 0, 5) as $line): $diff=(int)($line['diff_qty'] ?? 0); ?>
                <div class="small text-muted"><?=h(($line['title'] ?? '') ?: (($line['barcode'] ?? '') ?: ($line['scan_code'] ?? '')))?>：系統 <?=h($line['system_qty'] ?? 0)?> → 實盤 <?=h($line['actual_qty'] ?? $line['qty'] ?? 0)?>（差異 <?=h(($diff > 0 ? '+' : '') . $diff)?>）</div>
              <?php endforeach; ?>
              <?php if(count($differenceLines) > 5): ?><div class="small text-muted">另有 <?=h(count($differenceLines) - 5)?> 筆差異，請進入盤點單查看。</div><?php endif; ?>
            </div>
            <span class="status-pill">待確認 <?=h(count($differenceLines))?> 筆</span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>


    <div class="ops-card">
      <div class="section-head">
        <div>
          <h2>競標時間總覽 / 提醒物件</h2>
          <p class="muted">排程上架後，這裡會列出競標上架、截標、前 1 小時提醒與前 30 分鐘提醒，方便營運人員與 AI 後續提醒。</p>
        </div>
        <a class="button-like small" href="#schedule" data-jump-tab="schedule">管理排程</a>
      </div>
      <div class="responsive-table">
        <table>
          <thead><tr><th>產品</th><th>數量</th><th>競標上架</th><th>截標時間</th><th>前 1 小時提醒</th><th>前 30 分鐘提醒</th><th>狀態</th></tr></thead>
          <tbody>
            <?php foreach(array_slice($scheduleClosingSoon ?: $scheduleUpcoming, 0, 10) as $s): ?>
              <?php
                $closeText = (string)($s['close_at'] ?? '');
                $closeTs = $closeText ? strtotime($closeText) : false;
                $remind1h = $closeTs ? date('Y-m-d H:i', $closeTs - 3600) : '-';
                $remind30m = $closeTs ? date('Y-m-d H:i', $closeTs - 1800) : '-';
              ?>
              <tr>
                <td><b><?=h($s['product_id'] ?? '')?></b> <?=h($s['product_title'] ?? '')?></td>
                <td><?=h($s['quantity'] ?? 1)?></td>
                <td><?=h($s['publish_at'] ?? $s['scheduled_publish_at'] ?? '-')?></td>
                <td><?=h($closeText ?: '-')?></td>
                <td><?=h($remind1h)?></td>
                <td><?=h($remind30m)?></td>
                <td><?=h(($s['publish_status'] ?? '未上架') . ' / ' . ($s['order_status'] ?? '未結標'))?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$scheduleClosingSoon && !$scheduleUpcoming): ?><tr><td colspan="7" class="muted">目前尚未有可提醒的排程。</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="ops-card">
      <div class="section-head">
        <div>
          <h2>電商營運管理數據摘要</h2>
          <p class="muted">此區讀取產品庫存追溯、正式進貨入庫、銷售出貨與已建立的退回單；管家婆收付款明細不會算進進貨或退回。</p>
        </div>
        <a class="button-like small" href="#finance-analytics" data-jump-tab="finance-analytics">看完整圖表</a>
      </div>
      <div class="overview-metrics">
        <div class="metric"><span>產品建檔</span><strong><?=h(count($products))?> 筆</strong></div>
        <div class="metric"><span>可用庫存</span><strong><?=h($opsAvailableTotal)?> 件</strong></div>
        <div class="metric"><span>庫存成本</span><strong><?=money($opsStockCostValue)?></strong></div>
        <div class="metric"><span>預約庫存</span><strong><?=h($opsReservedTotal)?> 件</strong></div>
      </div>
      <div class="overview-metrics">
        <div class="metric"><span>累計營業額</span><strong><?=money($opsFinanceSummary['revenue'])?></strong></div>
        <div class="metric"><span>累計毛利</span><strong><?=money($opsFinanceSummary['profit'])?></strong></div>
        <div class="metric"><span>進貨數量</span><strong><?=h($opsFinanceSummary['stock_in'])?> 件</strong></div>
        <div class="metric"><span>出貨數量</span><strong><?=h($opsFinanceSummary['stock_out'])?> 件</strong></div>
        <div class="metric"><span>進貨退回</span><strong><?=money($opsFinanceSummary['purchase_return'])?></strong></div>
        <div class="metric"><span>銷貨退回</span><strong><?=money($opsFinanceSummary['sales_return'])?></strong></div>
      </div>
      <div class="responsive-table">
        <table>
          <thead><tr><th>期間</th><th>日期範圍</th><th>營業額</th><th>銷售成本</th><th>毛利</th><th>進貨</th><th>進貨退回</th><th>出貨</th><th>銷貨退回</th><th>虧損</th></tr></thead>
          <tbody>
            <?php foreach(array_slice($financeAnalytics, 0, 12) as $an): ?>
              <tr>
                <td><?=h($an['label'] ?? '-')?></td>
                <td><?=h(($an['start'] ?? '') . ' 至 ' . ($an['end'] ?? ''))?></td>
                <td><?=money($an['sales_revenue'] ?? 0)?></td>
                <td><?=money($an['sales_cost'] ?? 0)?></td>
                <td><?=money($an['gross_profit'] ?? 0)?></td>
                <td><?=h($an['stock_in_qty'] ?? 0)?> 件 / <?=money($an['stock_in_cost'] ?? 0)?></td>
                <td><?=h($an['purchase_return_qty'] ?? 0)?> 件 / <?=money($an['purchase_return_amount'] ?? 0)?></td>
                <td><?=h($an['sales_qty'] ?? 0)?> 件</td>
                <td><?=h($an['sales_return_qty'] ?? 0)?> 件 / <?=money($an['sales_return_amount'] ?? 0)?></td>
                <td><?=money($an['loss_amount'] ?? 0)?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$financeAnalytics): ?><tr><td colspan="10" class="muted">目前尚未有可彙整的進出貨或結算資料。</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="overview-board">
      <div class="ops-card">
        <h2>賺錢產品排行</h2>
        <div class="responsive-table">
          <table><thead><tr><th>產品</th><th>營業額</th><th>成本</th><th>毛利</th></tr></thead><tbody>
            <?php foreach(array_slice($opsProfitRows, 0, 10) as $row): ?>
              <tr><td><?=h(trim(($row['product_id'] ? $row['product_id'].' ' : '').$row['title']))?></td><td><?=money($row['revenue'])?></td><td><?=money($row['cost'])?></td><td><?=money($row['profit'])?></td></tr>
            <?php endforeach; ?>
            <?php if (!$opsProfitRows): ?><tr><td colspan="4" class="muted">目前尚無賺錢排行資料。</td></tr><?php endif; ?>
          </tbody></table>
        </div>
      </div>
      <div class="ops-card">
        <h2>虧損產品排行</h2>
        <div class="responsive-table">
          <table><thead><tr><th>產品</th><th>營業額</th><th>成本</th><th>虧損</th></tr></thead><tbody>
            <?php foreach(array_slice($opsLossRows, 0, 10) as $row): ?>
              <tr><td><?=h(trim(($row['product_id'] ? $row['product_id'].' ' : '').$row['title']))?></td><td><?=money($row['revenue'])?></td><td><?=money($row['cost'])?></td><td class="danger-text"><?=money($row['profit'])?></td></tr>
            <?php endforeach; ?>
            <?php if (!$opsLossRows): ?><tr><td colspan="4" class="muted">目前尚無虧損排行資料。</td></tr><?php endif; ?>
          </tbody></table>
        </div>
      </div>
    </div>
  </section>


<?php
  $opsStaffPermissionMap = [];
  foreach ($opsStaffPermissions as $row) {
      $accountKey = trim((string)($row['account'] ?? ''));
      if ($accountKey !== '') $opsStaffPermissionMap[mb_strtolower($accountKey, 'UTF-8')] = $row;
  }
  $opsStaffPermissionDisplayRows = [];
  $seenOpsStaffAccounts = [];
  foreach ($mainEmployees as $employeeRow) {
      $accountKey = trim((string)($employeeRow['account'] ?? ''));
      if ($accountKey === '') continue;
      $lookupKey = mb_strtolower($accountKey, 'UTF-8');
      $permissionRow = $opsStaffPermissionMap[$lookupKey] ?? [];
      $opsStaffPermissionDisplayRows[] = array_merge($permissionRow, [
          'account' => $accountKey,
          'name' => $employeeRow['name'] ?? $accountKey,
          'department' => $employeeRow['department'] ?? '',
          'synced_from_main' => true,
          'is_configured' => !empty($permissionRow),
      ]);
      $seenOpsStaffAccounts[$lookupKey] = true;
  }
  foreach ($opsStaffPermissions as $row) {
      $accountKey = trim((string)($row['account'] ?? ''));
      if ($accountKey === '') continue;
      $lookupKey = mb_strtolower($accountKey, 'UTF-8');
      if (!isset($seenOpsStaffAccounts[$lookupKey])) {
          $opsStaffPermissionDisplayRows[] = array_merge($row, [
              'department' => $row['department'] ?? '',
              'synced_from_main' => false,
              'is_configured' => true,
          ]);
      }
  }
?>
<section class="ops-card ops-tab" id="staff-permissions">
    <div class="section-head">
      <div>
        <h2>第二層：電商營運細項權限設定</h2>
        <p class="muted">總部後台只控制員工能不能進入電商營運；這裡才是控制進入後能看哪些電商功能。最高管理員永遠保留全部功能。</p>
      </div>
    </div>
    <div class="notice-block">設定順序：先到總部後台「權限設定」勾選電商營運管理入口，再到這裡設定產品、庫存、單據、財務、排程等細項。員工名單會同步總部員工管理；非管理員未設定第二層時，只能看到控制台。</div>
    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_ops_staff_permission">
      <label>員工登入帳號<select name="staff_account" id="opsStaffAccountSelect" required>
        <option value="">請選擇總部員工</option>
        <?php foreach($mainEmployees as $employeeRow): ?>
          <option value="<?=h($employeeRow['account'] ?? '')?>" data-name="<?=h($employeeRow['name'] ?? '')?>" data-department="<?=h($employeeRow['department'] ?? '')?>"><?=h(trim(($employeeRow['name'] ?? '') . (($employeeRow['department'] ?? '') !== '' ? ' / ' . $employeeRow['department'] : '')))?></option>
        <?php endforeach; ?>
      </select><small class="muted">來源：總部後台「員工管理」。</small></label>
      <label>員工姓名<input name="staff_name" id="opsStaffNameInput" placeholder="選擇員工後自動帶入" readonly></label>
      <label>角色<select name="staff_role"><option>上架人員</option><option>截標人員</option><option>記單出貨</option><option>行政人員</option><option>管理總監</option></select></label>
      <div class="wide staff-permission-grid">
        <?php foreach($opsFunctionGroups as $groupName => $items): ?>
          <div class="permission-group">
            <h3><?=h($groupName)?></h3>
            <div class="permission-checks">
              <?php foreach($items as $tabId => $label): ?>
                <label class="check"><input type="checkbox" name="allowed_tabs[]" value="<?=h($tabId)?>" <?=($tabId==='overview'?'checked':'')?>> <?=h($label)?></label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="wide form-actions">
        <button type="button" class="secondary" onclick="setOpsStaffPermissionRole('shelf')">套用上架人員</button>
        <button type="button" class="secondary" onclick="setOpsStaffPermissionRole('ship')">套用記單出貨</button>
        <button type="button" class="secondary" onclick="setOpsStaffPermissionRole('warehouse')">套用倉管盤點</button>
        <button type="button" class="secondary" onclick="setOpsStaffPermissionRole('finance')">套用財務單據</button>
        <button type="button" class="secondary" onclick="setOpsStaffPermissionSelection(true)">全選功能</button>
        <button type="button" class="secondary" onclick="setOpsStaffPermissionSelection(false)">只留控制台</button>
        <button class="primary">儲存員工權限</button>
        <span class="muted">快速套用後仍可手動增減勾選。</span>
      </div>
    </form>
    <div class="table-wrap">
      <table>
        <thead><tr><th>帳號</th><th>姓名</th><th>部門</th><th>狀態</th><th>角色</th><th>可用功能</th><th>更新時間</th><th>操作</th></tr></thead>
        <tbody>
          <?php foreach($opsStaffPermissionDisplayRows as $row): ?>
            <tr>
              <td><?=h($row['account'] ?? '')?></td>
              <td><?=h($row['name'] ?? '')?></td>
              <td><?=h($row['department'] ?? '')?></td>
              <td><?=!empty($row['is_configured']) ? '<span class="success-text">已設定第二層</span>' : '<span class="danger-text">尚未設定</span>'?></td>
              <td><?=h($row['role'] ?? '')?></td>
              <td><?php $labels=[]; foreach((array)($row['allowed_tabs'] ?? []) as $tab){ if(isset($opsFunctionMap[$tab])) $labels[]=$opsFunctionMap[$tab]; } echo h(implode('、', $labels)); ?></td>
              <td><?=h($row['updated_at'] ?? ($row['created_at'] ?? ''))?></td>
              <td>
                <?php if(!empty($row['id'])): ?><form method="post" class="inline-form" onsubmit="return confirm('確定刪除此員工的電商營運權限設定？');">
                  <input type="hidden" name="action" value="delete_ops_staff_permission">
                  <input type="hidden" name="staff_id" value="<?=h($row['id'] ?? '')?>">
                  <button class="danger small">刪除設定</button>
                </form><?php else: ?><span class="muted">請在上方選員工後儲存</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$opsStaffPermissionDisplayRows): ?><tr><td colspan="8" class="muted">尚未建立總部員工資料。</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="ops-card ops-tab" id="company-profile">
    <h2>本公司資料</h2>
    <p class="muted">這裡保存公司基本資料，之後估價單、進貨單據、銷售單據與出貨資料都可共用。</p>
    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_company_profile">
      <label>公司名稱<input name="company_name" value="<?=h($companyProfile['company_name'] ?? '')?>" placeholder="例如：寶輝科技有限公司"></label>
      <label>電話<input name="phone" value="<?=h($companyProfile['phone'] ?? '')?>" placeholder="例如：039-773280"></label>
      <label>傳真<input name="fax" value="<?=h($companyProfile['fax'] ?? '')?>" placeholder="例如：039-773669"></label>
      <label>聯絡姓名<input name="contact_name" value="<?=h($companyProfile['contact_name'] ?? '')?>" placeholder="例如：郭先生 / 李先生 / 曾小姐"></label>
      <label class="wide">地址<input name="address" value="<?=h($companyProfile['address'] ?? '')?>" placeholder="公司地址"></label>
      <label>信箱<input name="email" type="email" value="<?=h($companyProfile['email'] ?? '')?>" placeholder="公司信箱"></label>
      <label>LINE<input name="line_id" value="<?=h($companyProfile['line_id'] ?? '')?>" placeholder="LINE ID 或官方帳號"></label>
      <label>微信<input name="wechat_id" value="<?=h($companyProfile['wechat_id'] ?? '')?>" placeholder="微信帳號"></label>
      <div class="wide form-actions">
        <button class="primary">儲存本公司資料</button>
        <span class="muted">最後更新：<?=h($companyProfile['updated_at'] ?? '尚未儲存')?></span>
      </div>
    </form>
  </section>

  <section class="ops-card ops-tab" id="products">
    <h2><?= $isEditingProduct ? '編輯產品' : '產品建檔' ?></h2>
    <p class="muted"><?= $isEditingProduct ? '這筆已建檔，可以直接改名稱、分類、顏色尺寸、倉位與圖片，再按「儲存產品修改」。' : '此頁只建立產品主檔：分類英文編號、列印條碼、名稱、顏色尺寸、成本、倉位與圖片。實際補庫存請到「進貨單據」，系統會留下進貨紀錄。' ?></p>
    <div class="form-actions"><a class="button-like" href="#stock-in" data-jump-tab="stock-in">去進貨單據</a><a class="button-like" href="#suppliers" data-jump-tab="suppliers">去廠商建檔</a></div>
    <form method="post" enctype="multipart/form-data" class="product-form" id="productMasterForm" autocomplete="off" action="operations.php#products">
      <input type="hidden" name="action" value="save_product">
      <input type="hidden" name="editing_product_id" value="<?=h($editProduct['id'] ?? '')?>">
      <div class="wide alert product-serial-note">編號格式：分類大綱英文碼＋流水＋P＋成本＋顏色碼。P 是排序分隔，一定要在成本前面。例如主機 COMPUTER → <b>COM001P15096</b>，COM001 是流水、P150 是成本、96 是顏色碼。儲存後可直接列印條碼。</div>
      <?php
        $editSerial = $isEditingProduct ? product_serial_base($editProduct) : '';
        $editPrintedBarcode = $isEditingProduct ? latest_cost_barcode($editProduct) : '';
      ?>
      <input type="hidden" name="id" id="productSerialInput" value="<?=h($editSerial ?: ($editProduct['id'] ?? ''))?>" data-system-value="<?=h($editSerial ?: ($editProduct['id'] ?? ''))?>" data-existing="<?=h($editSerial ?: ($editProduct['id'] ?? ''))?>">
      <label>產品編號 / 列印條碼<input name="barcode" id="productBarcodeInput" placeholder="例如 COM001P15096" value="<?=h($editPrintedBarcode ?: ($editProduct['barcode'] ?? ''))?>" data-existing="<?=h($editPrintedBarcode ?: ($editProduct['barcode'] ?? ''))?>" readonly><small class="muted" id="productBarcodeHint">先選主大綱與分類大綱、填成本、加入顏色後自動組成。格式固定為 英文流水 + P + 成本 + 顏色碼。</small></label>
      <label>產品名稱<input name="title" required value="<?=h($editProduct['title'] ?? '')?>"></label>
      <label>主大綱<select name="category_group" id="productCategoryGroupInput">
        <?php $editCategoryGroup = trim((string)($editProduct['category_group'] ?? '')); if ($editCategoryGroup === '' || in_array($editCategoryGroup, ['電腦部門', '服裝部門', '電腦', '服裝'], true)) $editCategoryGroup = '組裝硬體'; ?>
        <?php foreach($categoryGroupOptions as $groupName): ?><option value="<?=h($groupName)?>" <?=$editCategoryGroup===$groupName?'selected':''?>><?=h($groupName)?></option><?php endforeach; ?>
      </select><small class="muted">組裝硬體／男性專區／女性專區／生活周邊。倉別部門在下面另選，不會跟主大綱綁在一起。</small></label>
      <input type="hidden" name="category_type" id="productCategoryTypeInput" value="<?=h($editProduct['category_type'] ?? '')?>">
      <div class="spec-combo record-combo is-wide" id="productCategoryTypeComboBox">
        <div class="spec-combo-head">
          <label>分類大綱<input id="productCategoryTypeCombo" autocomplete="off" placeholder="組裝硬體選主機板；服裝選鞋子／包包；生活周邊選衛生紙／廚房用具" value="<?=h($editProduct['category_type'] ?? '')?>"><small class="muted">手打新分類後按儲存，下次就能選。</small></label>
          <button type="button" class="button-like" id="saveCategoryTypeOption">儲存此分類</button>
        </div>
        <div class="spec-combo-menu" id="productCategoryTypeMenu" hidden></div>
      </div>
      <div class="spec-combo record-combo" id="productCategoryBrandComboBox">
        <div class="spec-combo-head">
          <label>品牌<input name="category_brand" id="productCategoryBrandSelect" autocomplete="off" placeholder="可選或手打，例如 ASUS" value="<?=h($editProduct['category_brand'] ?? '')?>" data-current="<?=h($editProduct['category_brand'] ?? '')?>"><small class="muted">沒有的品牌直接打，按儲存後下次可選。</small></label>
          <button type="button" class="button-like" id="saveCategoryBrandOption">儲存此品牌</button>
        </div>
        <div class="spec-combo-menu" id="productCategoryBrandMenu" hidden></div>
      </div>
      <div class="spec-combo record-combo" id="productCategorySpecComboBox">
        <div class="spec-combo-head">
          <label>細分類<input name="category_spec" id="productCategorySpecSelect" autocomplete="off" placeholder="可選或手打，例如 RTX / LGA1700" value="<?=h($editProduct['category_spec'] ?? '')?>" data-current="<?=h($editProduct['category_spec'] ?? '')?>"><small class="muted">沒有的細分類直接打，按儲存後下次可選。</small></label>
          <button type="button" class="button-like" id="saveCategorySpecOption">儲存此細分類</button>
        </div>
        <div class="spec-combo-menu" id="productCategorySpecMenu" hidden></div>
      </div>
      <?php $currentProductCondition = ($editProduct['product_condition'] ?? '') === '全新品' ? '全新品' : '二手品'; ?>
      <label>商品狀態<select name="product_condition"><option value="二手品" <?=$currentProductCondition==='二手品'?'selected':''?>>二手品</option><option value="全新品" <?=$currentProductCondition==='全新品'?'selected':''?>>全新品</option></select><small class="muted">新建預設二手品；只有新品再改成全新品。</small></label>
      <label>部門<select name="department" id="productDepartmentSelect" data-current="<?=h($editProduct['department'] ?? '電腦部門')?>"><?php foreach($departmentOptions as $dept): ?><option value="<?=h($dept)?>" <?=($editProduct['department'] ?? '電腦部門')===$dept?'selected':''?>><?=h($dept)?></option><?php endforeach; ?></select></label>
      <div class="color-module-picker">
        <div class="color-module-picker-head">
          <label>顏色尺碼類別<select name="color_module" id="colorModuleSelect" required>
            <option value="">請選擇顏色尺碼模組</option>
            <?php foreach($colorModules as $module): ?>
              <option value="<?=h($module['id'] ?? '')?>" <?=($editProduct['color_module'] ?? '')===($module['id'] ?? '')?'selected':''?>><?=h($module['name'] ?? '')?></option>
            <?php endforeach; ?>
          </select><small class="muted">選模組後，下面顏色／尺寸只帶這個面板。沒有的類別按右邊新增。</small></label>
          <button type="button" class="button-like" id="toggleAddColorModule">新增模組面板</button>
        </div>
        <div class="color-module-add-panel" id="addColorModulePanel" hidden>
          <h3>新增顏色尺碼模組</h3>
          <label>模組名稱<input id="newColorModuleName" placeholder="例如：女裝常用 / 鞋類尺寸" autocomplete="off"></label>
          <label>顏色清單<textarea id="newColorModuleColors" rows="3" placeholder="黑、白、杏、米、粉；可用逗號或換行分隔"></textarea></label>
          <label>尺寸清單<textarea id="newColorModuleSizes" rows="3" placeholder="F、S、M、L、XL；可用逗號或換行分隔"></textarea></label>
          <div class="color-module-add-actions">
            <button type="button" class="primary" id="saveNewColorModule">儲存並套用此模組</button>
            <button type="button" class="button-like" id="cancelAddColorModule">取消</button>
          </div>
          <p class="muted" id="addColorModuleStatus">儲存後會立刻出現在上面的下拉選單，產品建檔資料不會被清掉。</p>
        </div>
      </div>
      <div class="product-code-picker">
        <label>顏色 / 顏色碼<select id="productColorPairSelect">
          <option value="">請先選顏色尺碼類別</option>
        </select></label>
        <button type="button" class="button-like" id="addProductColorPair">加入顏色</button>
        <input type="hidden" name="color" id="productColorInput" value="<?=h($editProduct['color'] ?? '')?>">
        <input type="hidden" name="color_code" id="productColorCodeInput" value="<?=h($editProduct['color_code'] ?? '')?>">
        <div class="product-picked-list" id="productColorPicked"></div>
      </div>
      <div class="product-code-picker">
        <label>尺寸 / 尺寸碼<select id="productSizePairSelect">
          <option value="">請先選顏色尺碼類別</option>
        </select></label>
        <button type="button" class="button-like" id="addProductSizePair">加入尺寸</button>
        <input type="hidden" name="size" id="productSizeInput" value="<?=h($editProduct['size'] ?? '')?>">
        <input type="hidden" name="size_code" id="productSizeCodeInput" value="<?=h($editProduct['size_code'] ?? '')?>">
        <div class="product-picked-list" id="productSizePicked"></div>
      </div>
      <div class="spec-combo" id="productSpecCombo">
        <div class="spec-combo-head">
          <label>規格<input name="spec" id="productSpecInput" placeholder="容量、材質、版本等，可打字或挑選" value="<?=h($editProduct['spec'] ?? '')?>" autocomplete="off"><small class="muted">可選清單或直接打字；離開欄位會自動記住，下次就能挑。</small></label>
          <button type="button" class="button-like" id="saveProductSpecOption">儲存此規格</button>
        </div>
        <div class="spec-combo-menu" id="productSpecMenu" hidden></div>
      </div>
      <div class="spec-combo" id="productPurchaseSourceCombo">
        <div class="spec-combo-head">
          <label>預設供應來源<input name="purchase_source" id="productPurchaseSource" placeholder="可選廠商或手打，例如 捷元 / 拼多多" value="<?=h($editProduct['purchase_source'] ?? '其他')?>" autocomplete="off" data-current="<?=h($editProduct['purchase_source'] ?? '其他')?>"><small class="muted" id="productPurchaseSourceStatus">可選既有廠商，或手打新名稱；離開欄位會自動存進廠商建檔與廠商搜尋分類。</small></label>
        </div>
        <div class="spec-combo-menu" id="productPurchaseSourceMenu" hidden></div>
      </div>
      <label>產品建檔幣別<input value="人民幣 CNY" readonly><input type="hidden" name="purchase_source_currency" id="productPurchaseCurrency" value="CNY"><small class="muted">產品主檔成本統一以人民幣保存。</small></label>
      <label>人民幣成本<input name="purchase_source_unit_cost" id="productPurchaseUnitCost" type="number" min="0" step="0.01" value="<?=h($editProduct['purchase_source_unit_cost'] ?? 0)?>"><small class="muted">輸入供應商人民幣原始成本。</small></label>
      <label>人民幣換算倍率<input name="purchase_exchange_rate" id="productPurchaseRate" type="number" min="0.0001" step="0.0001" value="<?=h($editProduct['purchase_exchange_rate'] ?? ($purchaseCostSettings['rmb_fixed_rate'] ?? 5))?>"></label>
      <label>條碼台幣成本<input name="cost" id="productCostInput" type="number" min="0" step="0.01" value="<?=h($editProduct['cost'] ?? 0)?>" readonly><small class="muted">由人民幣成本 × 倍率自動換算；條碼、庫存成本與財務報表使用此台幣金額。</small><small class="muted" id="productPurchaseConversionHint" aria-live="polite"></small></label>
      <label>寶輝實際售價<input name="sale_price" type="number" min="0" step="1" value="<?=h($editProduct['sale_price'] ?? 0)?>"><small class="muted">商城與估價單優先使用此售價；0 元不會自動公開。</small></label>
      <label class="check">前台商城發布<input name="publish_storefront" type="checkbox" value="1" <?=!empty($editProduct['publish_storefront'])?'checked':''?>><small class="muted">需有寶輝實際售價才會顯示。</small></label>
      <div class="wide alert">外部自有組裝主機屬硬性排除項目；僅可上架零組件、周邊與公司核准的品牌成品。</div>
      <label>倉別<select name="warehouse_name" id="productWarehouseSelect" data-current="<?=h(trim((string)($editProduct['warehouse_name'] ?? '')) !== '' ? $editProduct['warehouse_name'] : default_warehouse_name($editProduct['department'] ?? '電腦部門'))?>"><option value="">請選擇倉別</option></select></label>
      <label>倉架名稱<select name="shelf_code" id="productShelfSelect" data-current="<?=h($editProduct['shelf_code'] ?? '')?>"><option value="">還沒放上去</option></select><span class="shelf-add-inline"><input id="productShelfAddInput" placeholder="例如 A01" autocomplete="off"><button type="button" class="secondary small" id="productShelfAddBtn">+ 新增倉架</button></span><small class="muted">沒選倉架名稱代表還沒放上去。這個倉別還沒有倉架時，可在這裡新增名稱。</small></label>
      <label>倉架位置<select name="warehouse_location" id="productLocationSelect" data-current="<?=h($editProduct['warehouse_location'] ?? '')?>"><option value="">還沒放上去</option><option value="上層">上層</option><option value="下層">下層</option></select><small class="muted">只有上層／下層；先選倉架名稱才能指定。</small></label>
      <div class="wide product-image-guide">圖片規則：商品主圖只放 1 張，系統排程、清單、買家核對會優先使用主圖；其他產品照片可一次多選，放細節、規格、瑕疵、不同角度。</div>
      <?php if($isEditingProduct): $currentImages = product_images($editProduct); ?>
      <div class="wide product-image-preview">
        <div class="product-main-preview">
          <h3>目前主圖</h3>
          <?php if(!empty($editProduct['image'])): ?><img class="zoomable" src="<?=h($editProduct['image'])?>" alt="商品主圖"><?php else: ?><span class="muted">尚未上傳主圖</span><?php endif; ?>
        </div>
        <div>
          <h3>目前其他產品照片</h3>
          <div class="product-gallery">
            <?php foreach(($editProduct['extra_images'] ?? []) as $img): if(!$img) continue; ?><img class="zoomable" src="<?=h($img)?>" alt="產品照片"><?php endforeach; ?>
            <?php if(empty($editProduct['extra_images'])): ?><span class="empty">尚未上傳其他產品照片</span><?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <?php render_photo_capture_fields('image', 'photos', [
        'title' => '拍照 / 上傳產品圖片',
        'hint' => '手機可直接拍照；電腦可用相簿選檔，或 Ctrl+V 貼上圖片。主圖限 1 張（會取代目前主圖），細圖可連續加拍，儲存後會追加保留。',
        'main_label' => '商品主圖（限 1 張，會取代目前主圖）',
        'extra_label' => '其他產品照片（可多張追加）',
      ]); ?>
      <label class="wide check">前台產品圖片確認<input name="public_image_approved" type="checkbox" value="1" <?=!empty($editProduct['public_image_approved'])?'checked':''?>><small class="muted">僅在圖片已確認為原廠提供或寶輝科技自有時勾選；未勾選時商城使用中性預設圖。</small></label>
      <div class="wide product-upload-preview" id="productUploadPreview" hidden>
        <div>
          <h3>本次選擇主圖</h3>
          <div class="product-upload-main" id="productUploadMain"><span class="muted">尚未選主圖</span></div>
        </div>
        <div>
          <h3>本次選擇其他照片</h3>
          <div class="product-upload-gallery" id="productUploadGallery"><span class="muted">尚未選其他照片</span></div>
        </div>
      </div>
      <label class="wide">產品描述<textarea name="description_source" id="productDescriptionSource" rows="5" placeholder="先貼原始說明、簡體文案、供應商資料或注意事項。按「轉換」後再貼到下面的 AI 整理欄。"><?=h($editProduct['description_source'] ?? '')?></textarea><small class="muted">這格是原文，給 AI 轉換用；排程上架不會直接用這段。</small></label>
      <div class="wide form-actions">
        <button type="button" class="secondary" id="convertProductDescription">轉換成上架描述</button>
        <button type="button" class="secondary" id="copyProductDescriptionPrompt">複製轉換提示</button>
      </div>
      <label class="wide">產品描述（AI 整理後貼這裡）<textarea name="description" id="productDescriptionAi" rows="5" placeholder="把 ChatGPT 整理後的繁體描述貼這裡。排程上架會帶入這段內容。"><?=h($editProduct['description'] ?? '')?></textarea><small class="muted">請先填上面的產品描述，再轉換。這格才是上架用文案。</small></label>
      <div class="wide form-actions">
        <button class="primary" id="productMasterSubmit"><?= $isEditingProduct ? '儲存產品修改' : '新增產品建檔' ?></button>
        <?php if($isEditingProduct): ?>
          <a class="button-like" target="_blank" rel="noopener" href="operations.php?print_cost_barcode=<?=urlencode($editProduct['id'] ?? '')?>&label_size=40x30">列印條碼 40×30</a>
          <a class="button-like" target="_blank" rel="noopener" href="operations.php?print_cost_barcode=<?=urlencode($editProduct['id'] ?? '')?>&label_size=30x30">列印條碼 30×30</a>
          <a class="button-like" href="operations.php#products">取消編輯 / 新增下一筆</a>
        <?php endif; ?>
      </div>
    </form>
    <div class="sub-card product-quick-operations">
      <div class="section-head">
        <div><h3>條碼快速入庫</h3><p class="muted">舊系統現貨用直接成本入庫，不加關稅、運費或倉別加價。掃條碼、填數量與台幣成本即可。</p></div>
        <a class="button-like" href="#inventory-count" data-tab-link="inventory-count">開啟完整盤點功能</a>
      </div>
      <form method="post" enctype="multipart/form-data" class="product-form" id="productQuickStockForm">
        <input type="hidden" name="action" value="stock_in">
        <input type="hidden" name="stock_doc_no" value="">
        <input type="hidden" name="stock_doc_date" value="<?=h(date('Y-m-d'))?>">
        <input type="hidden" name="stock_allocation_method" value="quantity">
        <input type="hidden" name="stock_direct_cost" value="1">
        <input type="hidden" name="stock_source" id="productQuickStockSource" value="舊系統現貨">
        <input type="hidden" name="stock_currency" id="productQuickStockCurrency" value="TWD">
        <input type="hidden" name="stock_exchange_rate" id="productQuickStockRate" value="1">
        <label class="wide">掃描條碼 / 產品編號<input name="stock_product_id" id="productQuickStockBarcode" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="掃描後按 Enter"></label>
        <div class="wide ops-alert" id="productQuickStockProductInfo">等待掃描產品條碼。</div>
        <label>入庫數量<input name="stock_qty" id="productQuickStockQty" type="number" min="1" value="1" required></label>
        <label>直接成本（台幣）<input name="stock_unit_cost" id="productQuickStockUnitCost" type="number" min="0" step="0.01" value="0" required><small class="muted">掃條碼後帶入現有成本，可改。不加運費、關稅、倉別加價。</small></label>
        <input type="hidden" name="stock_department" id="productQuickStockDepartment" value="電腦部門">
        <label>倉別<select name="stock_warehouse_name" id="productQuickStockWarehouse" data-current="電腦倉"><option value="">請選擇倉別</option></select></label>
        <label>倉架名稱<select name="stock_shelf_code" id="productQuickStockShelf"><option value="">還沒放上去</option></select><span class="shelf-add-inline"><input id="productQuickStockShelfAdd" placeholder="例如 A01" autocomplete="off"><button type="button" class="secondary small" id="productQuickStockShelfAddBtn">+ 新增倉架</button></span><small class="muted">沒選倉架名稱代表還沒放上去。這個倉別還沒有倉架時，先選倉別，再在這裡新增倉架名稱。</small></label>
        <label>倉架位置<select name="stock_location" id="productQuickStockLocation"><option value="">還沒放上去</option><option value="上層">上層</option><option value="下層">下層</option></select><small class="muted">只有上層／下層。</small></label>
        <?php render_photo_capture_fields('stock_main_image', 'stock_extra_images', [
          'title' => '入庫時順便拍照',
          'hint' => '掃完條碼、選好倉別後，可拍照、選相簿或 Ctrl+V 貼上主圖／細圖。確認入庫時會一併存到這筆產品。',
          'main_label' => '主圖（1 張，會取代）',
          'extra_label' => '其他細圖（可多張追加）',
        ]); ?>
        <label class="wide">備註<input name="stock_note" placeholder="舊系統現貨、快速入庫說明"></label>
        <div class="wide document-total-bar"><span>入庫小計 <b id="productQuickStockBase">$0</b></span><span>直接單位成本 <b id="productQuickStockLanded">$0</b></span></div>
        <button class="primary" id="productQuickStockSubmit">確認直接成本並入庫</button>
      </form>
      <div class="ops-alert">盤點作業會使用同一份商品與庫存資料，支援條碼掃描、數量累加、差異表格與盤點時間。請按右上方「開啟完整盤點功能」。</div>
    </div>
    <div class="sub-card">
      <h3>Google 表 / CSV 匯入產品庫存</h3>
      <form method="post" class="product-form">
        <input type="hidden" name="action" value="sync_default_product_sheet">
        <div class="wide muted">固定同步來源：Google 雲端表「新上架 / 庫存數量 / 上架後數量 / 已售出」。按下後會用產品編號或條碼比對，已有就更新，沒有就新增。</div>
        <button class="primary">同步指定雲端表</button>
      </form>
      <form method="post" enctype="multipart/form-data" class="product-form" autocomplete="off">
        <input type="hidden" name="action" value="import_products_csv">
        <label class="wide">Google 表連結或 CSV 連結<input name="sheet_csv_url" placeholder="貼上 Google Sheet 連結，需開放檢視或已發布"></label>
        <label>或上傳 CSV<input name="csv_file" type="file" accept=".csv,text/csv"></label>
        <div class="wide muted">可辨識欄位：產品編號、條碼、產品名稱、顏色、尺碼、規格、成本、庫存數量、上架後數量、已售出、倉位、新上架。系統會用產品編號或條碼比對，已有就更新，沒有就新增。</div>
        <button class="secondary">匯入產品庫存</button>
      </form>
    </div>
    <form method="get" action="operations.php#products" class="inline-filter product-list-filter"><label>現有產品關鍵字<input id="productQuickSearch" name="product_q" autocomplete="off" placeholder="輸入第一個字即可搜尋：編號 / 條碼 / 名稱 / 顏色 / 尺寸 / 規格" value="<?=h($productListQ)?>"></label><label>商品狀態<select name="product_condition"><option value="">全部</option><option value="全新品" <?=($productListCondition==='全新品'?'selected':'')?>>全新品</option><option value="二手品" <?=($productListCondition==='二手品'?'selected':'')?>>二手品</option></select></label><button class="button-like">搜尋產品</button><?php if($productListQ!=='' || $productListCondition!==''): ?><a class="button-like" href="operations.php#products">清除搜尋</a><?php endif; ?><span class="muted">目前第 <?=h($productListPage)?> / <?=h($productListPages)?> 頁，每頁 10 筆；顯示 <?=h(count($productListRows))?> / <?=h($productListTotal)?> 筆，總產品 <?=h(count($products))?> 筆。</span></form>
<form method="post" class="bulk-product-form" onsubmit="return confirm('確定刪除勾選的產品？已排程產品會自動跳過。');">
      <input type="hidden" name="action" value="delete_products_bulk">
      <div class="bulk-actions">
        <button class="danger" type="submit">刪除勾選產品</button>
      </div>
      <div class="sub-card">
        <h3>AI 產品描述素材</h3>
        <p class="muted">勾選產品後按下產生，會把產品編號、名稱、規格、原始產品描述、主圖與其他圖片整理在下面，方便複製到 ChatGPT 轉繁體中文與補強說明。</p>
        <div class="form-actions">
          <button type="button" class="secondary" id="buildAiMaterial">產生勾選產品素材</button>
          <button type="button" class="secondary" id="copyAiMaterial">複製素材</button>
        </div>
        <textarea id="aiMaterialOutput" class="wide" rows="10" placeholder="勾選產品後，按「產生勾選產品素材」。"></textarea>
      </div>
    
<?php if(!$isEditingProduct && $productListPages > 1): ?><div class="pager product-pager"><?php for($pg=max(1,$productListPage-3); $pg<=min($productListPages,$productListPage+3); $pg++): ?><a class="button-like small <?= $pg===$productListPage ? 'active' : '' ?>" href="operations.php?product_q=<?=urlencode($productListQ)?>&product_condition=<?=urlencode($productListCondition)?>&product_page=<?=$pg?>#products"><?=$pg?></a><?php endfor; ?></div><?php endif; ?>
<div class="table-wrap"><table><thead><tr><th><input type="checkbox" id="checkAllProducts" onclick="document.querySelectorAll('.product-check').forEach(cb=>cb.checked=this.checked)"></th><th>圖片</th><th>編號（分類＋P成本＋顏色）</th><th>名稱</th><th>狀態</th><th>分類</th><th>顏色 / 尺碼 / 規格</th><th>人民幣成本 / 條碼台幣</th><th>售價</th><th>前台</th><th>庫存</th><th>倉別 / 倉架 / 位置</th><th>操作</th></tr></thead><tbody>
      <?php foreach($productListRows as $p): $available=stock_available($p); $reservedTotal=stock_reserved_total($p); $cloudReserved=cloud_auction_reserved($p); $productListStockName=trim((string)($p['warehouse_name']??'')); $productListPosition=stock_position_label($p['shelf_code']??'', $p['warehouse_location']??''); if($productListPosition===$productListStockName) $productListPosition=''; $productListCategory=trim(implode(' / ', array_filter([$p['category_group']??'', $p['category_type']??'', $p['category_brand']??'', $p['category_spec']??''], function($v){ return trim((string)$v) !== ''; }))); ?>
      <tr data-product-search-row data-product-search-text="<?=h(trim(($p['id']??'').' '.($p['barcode']??'').' '.($p['title']??'').' '.($p['color']??'').' '.($p['color_code']??'').' '.($p['size']??'').' '.($p['size_code']??'').' '.($p['spec']??'').' '.($p['category_group']??'').' '.($p['category_type']??'').' '.($p['category_brand']??'').' '.($p['category_spec']??'')))?>" data-product-id="<?=h($p['id']??'')?>" data-product-title="<?=h($p['title']??'')?>" data-product-spec="<?=h(trim(($p['color']??'').' / '.($p['size']??'').' / '.($p['spec']??''), ' /'))?>" data-product-desc="<?=h(($p['description_source'] ?? '') !== '' ? ($p['description_source'] ?? '') : ($p['description']??''))?>" data-product-images="<?=h(implode('\\n', product_images($p)))?>">
        <td><input class="product-check" type="checkbox" name="product_ids[]" value="<?=h($p['id']??'')?>"></td>
        <td>
          <?php $imgs = product_images($p); ?>
          <?php if(!empty($p['image'])): ?><span class="product-list-main"><img class="thumb zoomable" src="<?=h($p['image'])?>" title="主圖"></span><?php else: ?><span class="muted">無主圖</span><?php endif; ?>
          <div class="photo-capture-actions">
            <label class="photo-btn camera small">拍照上傳<input type="file" accept="image/*" capture="environment" data-quick-photo-product="<?=h($p['id']??'')?>" data-quick-photo-role="main"></label>
            <button type="button" class="photo-btn paste small" data-photo-paste-self>貼上</button>
            <button type="button" class="secondary small product-capture-button" data-product-id="<?=h($p['id']??'')?>" onclick="captureProductMainImage(this)">✂ 擷取主圖</button>
          </div>
          <?php if(count($imgs) > 1): ?><div class="product-list-gallery"><?php foreach(array_slice($imgs, 1, 6) as $img): ?><img class="zoomable" src="<?=h($img)?>" title="其他產品照片"><?php endforeach; ?></div><?php endif; ?>
          <?php if(count($imgs) > 1): ?><small class="muted">共 <?=h(count($imgs))?> 張</small><?php endif; ?>
        </td>
        <td><b><?=h(latest_cost_barcode($p) ?: ($p['barcode'] ?? $p['id'] ?? ''))?></b><br><span class="muted">流水 <?=h(product_serial_base($p) ?: ($p['id'] ?? ''))?></span></td>
        <td><?=h($p['title']??'')?></td>
        <td><?=h(($p['product_condition'] ?? '') ?: '未設定')?></td>
        <td><?=h($productListCategory ?: '-')?></td>
        <td><?=h(($p['color']??'').' / '.($p['size']??'').' / '.($p['spec']??''))?></td>
        <td><b>¥<?=h(number_format((float)($p['purchase_source_unit_cost'] ?? 0), 2))?></b><br><span class="muted">CNY × <?=h($p['purchase_exchange_rate'] ?? ($purchaseCostSettings['rmb_fixed_rate'] ?? 5))?></span><br><span class="muted">條碼台幣 <?=money($p['cost']??0)?></span><br><span class="muted">最新條碼 <?=h(latest_cost_barcode($p))?></span></td>
        <td><b><?=money($p['sale_price']??0)?></b></td>
        <td><?=!empty($p['publish_storefront'])?'<b class="success-text">已發布</b>':'未發布'?></td>
        <td>總 <?=h($p['stock_total']??0)?>｜預約 <?=h($reservedTotal)?><?php if($cloudReserved): ?>（競標 <?=h($cloudReserved)?>）<?php endif; ?>｜已售 <?=h($p['stock_sold']??0)?>｜可用 <?=h($available)?><?php if(!empty($p['cloud_auction_locked'])): ?><br><b class="stock-warning">競標中鎖倉</b><?php endif; ?></td>
        <td><?=h($productListStockName ?: '-')?><?php if($productListPosition): ?><br><span class="muted"><?=h($productListPosition)?></span><?php endif; ?></td>
        <td>
          <a class="button-like small" href="operations.php?edit_product=<?=urlencode($p['id']??'')?>#products">編輯</a>
          <a class="button-like small" target="_blank" rel="noopener" href="operations.php?print_cost_barcode=<?=urlencode($p['id']??'')?>&label_size=40x30">條碼 40×30</a>
          <a class="button-like small" target="_blank" rel="noopener" href="operations.php?print_cost_barcode=<?=urlencode($p['id']??'')?>&label_size=30x30">條碼 30×30</a>
          <form method="post" class="inline-form" onsubmit="return confirm('確定刪除此產品？沒有排程引用才會刪除。');">
            <input type="hidden" name="action" value="delete_product">
            <input type="hidden" name="id" value="<?=h($p['id']??'')?>">
            <button class="danger small" type="submit">刪除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
      <div class="bulk-actions bottom"><button class="danger" type="submit">刪除勾選產品</button></div>
    </form>
  </section>

<script>
(function(){
  if (window.productQuickSearchReady20260708) return;
  window.productQuickSearchReady20260708 = true;
  function bindProductQuickSearch(){
    var input = document.getElementById('productQuickSearch');
    if (!input) return;
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-product-search-row]'));
    var apply = function(){
      var q = (input.value || '').trim().toLowerCase();
      rows.forEach(function(row){
        var text = (row.getAttribute('data-product-search-text') || '').toLowerCase();
        row.style.display = (!q || text.indexOf(q) !== -1) ? '' : 'none';
      });
    };
    input.addEventListener('input', apply);
    apply();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindProductQuickSearch);
  else bindProductQuickSearch();
})();
</script>
  <section class="ops-card ops-tab" id="product-categories">
    <h2>產品分類</h2>
    <p class="muted">分類固定依序：主大綱 → 分類大綱 → 品牌 → 細分類 → 商品。例如組裝硬體 → 主機板 → 華碩 → LGA1700 → 該商品；男性專區／女性專區分開 → 鞋子／包包 → 商品；生活周邊 → 衛生紙／廚房用具／手工具／行李箱 → 商品。</p>
    <form method="post" action="operations.php#product-categories" class="inline-form" onsubmit="return confirm('會依商品名稱自動分辨並搬到新主大綱。倉別（電腦部門／服裝部門）不會改。');">
      <input type="hidden" name="action" value="apply_product_category_tree">
      <button class="primary" type="submit">依此模式重新分辨並搬移現有商品</button>
    </form>
    <form method="post" action="operations.php#product-categories" class="product-form">
      <input type="hidden" name="action" value="save_product_category">
      <label>主大綱<select name="category_group" required>
        <option value="">請選擇主大綱</option>
        <?php foreach($categoryGroupOptions as $groupName): ?><option value="<?=h($groupName)?>"><?=h($groupName)?></option><?php endforeach; ?>
      </select></label>
      <label>分類大綱<input name="category_type" list="computerCategoryTypeSuggestions" required placeholder="主機板／鞋子／包包／衛生紙／廚房用具"></label>
      <label>分類英文碼<input name="category_type_code" maxlength="8" pattern="[A-Za-z0-9]+" placeholder="例如：COM / GPU / MB"><small class="muted">產品編號用這個英文碼連流水。主機 COMPUTER 請填 COM。</small></label>
      <label>品牌<input name="category_brand" list="computerBrandSuggestions" placeholder="例如：ASUS / MSI / 不指定品牌"></label>
      <label>細分類<input name="category_spec" placeholder="例如：RTX 系列 / DDR5 / NVMe"></label>
      <datalist id="computerCategoryTypeSuggestions">
        <option value="主機板"><option value="顯示卡"><option value="處理器"><option value="記憶體"><option value="硬碟SSD"><option value="電源供應器"><option value="電腦機箱"><option value="散熱設備"><option value="電腦螢幕"><option value="筆電"><option value="電腦周邊"><option value="鞋子"><option value="包包"><option value="上衣"><option value="褲子"><option value="衛生紙"><option value="廚房用具"><option value="手工具"><option value="行李箱"><option value="健康設備"><option value="燈飾">
      </datalist>
      <datalist id="computerBrandSuggestions">
        <option value="Intel"><option value="AMD"><option value="ASUS"><option value="MSI"><option value="GIGABYTE"><option value="ASRock"><option value="Kingston"><option value="Crucial"><option value="ADATA"><option value="TeamGroup"><option value="Transcend"><option value="Samsung"><option value="WD"><option value="Seagate"><option value="KIOXIA"><option value="SK hynix"><option value="ZOTAC"><option value="SAPPHIRE"><option value="PowerColor"><option value="Acer"><option value="BenQ"><option value="LG"><option value="Dell"><option value="Cooler Master"><option value="Thermaltake"><option value="CORSAIR"><option value="Seasonic"><option value="Logitech"><option value="TP-Link"><option value="D-Link"><option value="Synology"><option value="QNAP">
      </datalist>
      <label>條碼前綴<input name="category_barcode_prefix" maxlength="16" pattern="[A-Za-z0-9]+" placeholder="可留空，預設等於分類英文碼"><small class="muted">可留空。產品編號已改為分類英文碼＋P成本＋顏色碼。</small></label>
      <label>排序<input name="category_sort" type="number" value="0"></label>
      <label>商城主選單排序<input name="storefront_order" type="number" value="0"><small class="muted">同一分類大綱會共用此順序，也可在下方拖曳。</small></label>
      <label class="wide">備註<input name="category_note" placeholder="內部辨識用"></label>
      <button class="primary">新增產品分類</button>
    </form>
    <form id="categoryBulkDeleteForm" method="post" action="operations.php#product-categories" onsubmit="return confirm('確定刪除勾選的產品分類？產品既有分類文字不會被清空。');">
      <input type="hidden" name="action" value="bulk_delete_product_categories">
    </form>
    <div class="bulk-actions">
      <label class="check"><input type="checkbox" data-select-all="category_ids[]"> 全選產品分類</label>
      <button class="danger" type="submit" form="categoryBulkDeleteForm">刪除勾選分類</button>
      <span class="muted">可用上移 / 下移調整顯示順序。</span>
    </div>
    <?php
      $opsCategoryLimit = 10;
      $opsCategoryPage = max(1, (int)($_GET['category_page'] ?? 1));
      $opsCategoryTotal = count($productCategories);
      $opsCategoryPages = max(1, (int)ceil($opsCategoryTotal / $opsCategoryLimit));
      $opsCategoryPage = min($opsCategoryPage, $opsCategoryPages);
      $categoryShownPage = array_slice($productCategories, ($opsCategoryPage - 1) * $opsCategoryLimit, $opsCategoryLimit);
      $opsCategoryPageUrl = function($page) { return 'operations.php?' . http_build_query(['category_page' => $page]) . '#product-categories'; };
    ?>
    <?php
      $categoryBuckets = [];
      $ensureCategoryBucket = function($group, $type) use (&$categoryBuckets) {
          $group = trim((string)$group);
          $type = trim((string)$type);
          if ($group === '') $group = '未分類群組';
          if ($type === '') $type = '未分類類別';
          $key = $group . '|' . $type;
          if (!isset($categoryBuckets[$key])) {
              $categoryBuckets[$key] = [
                  'group' => $group,
                  'type' => $type,
                  'rules' => [],
                  'products' => [],
                  'brands' => [],
                  'specs' => [],
                  'storefront_order' => PHP_INT_MAX,
                  'leaf_sort' => PHP_INT_MAX,
              ];
          }
          return $key;
      };
      foreach ($productCategories as $c) {
          $key = $ensureCategoryBucket($c['group'] ?? '', $c['type'] ?? '');
          $categoryBuckets[$key]['rules'][] = $c;
          $menuOrder = (int)($c['storefront_order'] ?? 0);
          $leafSort = (int)($c['sort'] ?? 0);
          if ($menuOrder > 0) $categoryBuckets[$key]['storefront_order'] = min($categoryBuckets[$key]['storefront_order'], $menuOrder);
          $categoryBuckets[$key]['leaf_sort'] = min($categoryBuckets[$key]['leaf_sort'], $leafSort);
          $brand = trim((string)($c['brand'] ?? ''));
          $spec = trim((string)($c['spec'] ?? ''));
          if ($brand !== '') $categoryBuckets[$key]['brands'][$brand] = true;
          if ($spec !== '') $categoryBuckets[$key]['specs'][$spec] = true;
      }
      foreach ($products as $p) {
          $key = $ensureCategoryBucket($p['category_group'] ?? ($p['department'] ?? ''), $p['category_type'] ?? ($p['main_category'] ?? ''));
          $categoryBuckets[$key]['products'][] = $p;
          $brand = trim((string)($p['category_brand'] ?? ''));
          $spec = trim((string)($p['category_spec'] ?? ($p['spec'] ?? '')));
          if ($brand !== '') $categoryBuckets[$key]['brands'][$brand] = true;
          if ($spec !== '') $categoryBuckets[$key]['specs'][$spec] = true;
      }
      uasort($categoryBuckets, function($a, $b) {
          $g = strnatcmp((string)$a['group'], (string)$b['group']);
          if ($g !== 0) return $g;
          $orderCompare = ((int)$a['storefront_order']) <=> ((int)$b['storefront_order']);
          if ($orderCompare !== 0) return $orderCompare;
          $leafCompare = ((int)$a['leaf_sort']) <=> ((int)$b['leaf_sort']);
          return $leafCompare !== 0 ? $leafCompare : strnatcmp((string)$a['type'], (string)$b['type']);
      });
      $categoryDestinationBuckets = [];
      foreach ($productCategories as $categoryRow) {
          $destinationGroup = trim((string)($categoryRow['group'] ?? ''));
          $destinationType = trim((string)($categoryRow['type'] ?? ''));
          if ($destinationGroup === '' || $destinationType === '') continue;
          $destinationKey = $destinationGroup . '|' . $destinationType;
          $categoryDestinationBuckets[$destinationKey] = [$destinationGroup, $destinationType];
      }
      uasort($categoryDestinationBuckets, function($a, $b) {
          $g = strnatcmp((string)$a[0], (string)$b[0]);
          return $g !== 0 ? $g : strnatcmp((string)$a[1], (string)$b[1]);
      });
    ?>
    <style>#product-categories > .table-wrap,#product-categories > .pager{display:none}</style>
    <p class="muted">商城公開選單會依「主大綱 → 分類大綱 → 品牌 → 細分類 → 商品」分層進入。可拖曳同一主大綱內的分類大綱調整商城順序；手機版可使用上移、下移。</p>
    <form id="categoryTypeOrderForm" method="post" action="operations.php#product-categories" hidden>
      <input type="hidden" name="action" value="reorder_product_category_types">
      <input type="hidden" name="category_group" id="categoryTypeOrderGroup">
      <input type="hidden" name="type_order" id="categoryTypeOrderValue">
    </form>
    <div class="category-bucket-list">
      <?php foreach($categoryBuckets as $bucket): ?>
        <?php
          $brandText = implode('、', array_slice(array_keys($bucket['brands']), 0, 8));
          $specText = implode('、', array_slice(array_keys($bucket['specs']), 0, 8));
        ?>
        <details class="category-bucket-card" draggable="true" data-category-group="<?=h($bucket['group'])?>" data-category-type="<?=h($bucket['type'])?>">
          <summary>
            <span>
              <b><span class="category-drag-handle" title="拖曳調整商城順序">⋮⋮</span><?=h($bucket['group'])?> / <?=h($bucket['type'])?></b>
              <small>品牌：<?=h($brandText ?: '待整理')?>｜細分類：<?=h($specText ?: '待整理')?></small>
            </span>
            <span class="category-bucket-count">商城排序 <?=h($bucket['storefront_order'] === PHP_INT_MAX ? '未設定' : $bucket['storefront_order'])?>｜<?=h(count($bucket['products']))?> 件產品</span>
          </summary>
          <div class="category-bucket-inner">
            <div class="category-order-tools">
              <b>商城主選單：<?=h($bucket['type'])?></b>
              <form method="post" class="inline-form" action="operations.php#product-categories"><input type="hidden" name="action" value="move_product_category_type"><input type="hidden" name="category_group" value="<?=h($bucket['group'])?>"><input type="hidden" name="category_type" value="<?=h($bucket['type'])?>"><input type="hidden" name="direction" value="up"><button class="secondary small" type="submit">上移</button></form>
              <form method="post" class="inline-form" action="operations.php#product-categories"><input type="hidden" name="action" value="move_product_category_type"><input type="hidden" name="category_group" value="<?=h($bucket['group'])?>"><input type="hidden" name="category_type" value="<?=h($bucket['type'])?>"><input type="hidden" name="direction" value="down"><button class="secondary small" type="submit">下移</button></form>
            </div>
            <form method="post" action="operations.php#product-categories" class="category-bucket-rename-form" onsubmit="return confirm('確定更改整個類別名稱？所有細分類與已建檔產品會一起同步。');">
              <input type="hidden" name="action" value="rename_product_category_bucket">
              <input type="hidden" name="source_bucket" value="<?=h(json_encode([$bucket['group'], $bucket['type']], JSON_UNESCAPED_UNICODE))?>">
              <label>主大綱名稱<input name="target_group" value="<?=h($bucket['group'])?>" required></label>
              <label>分類大綱名稱<input name="target_type" value="<?=h($bucket['type'])?>" required></label>
              <button class="primary" type="submit">儲存類別名稱</button>
              <span class="muted">會同步更新這個類別底下的所有細分類與產品。</span>
            </form>
            <h3>分類規則</h3>
            <?php foreach($bucket['rules'] as $categoryEditRow): ?>
              <?php $categoryEditFormId = 'categoryEdit-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($categoryEditRow['id'] ?? '')); ?>
              <?php $categoryMoveFormId = 'categoryMove-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($categoryEditRow['id'] ?? '')); ?>
              <form id="<?=h($categoryEditFormId)?>" method="post" action="operations.php#product-categories">
                <input type="hidden" name="action" value="save_product_category">
                <input type="hidden" name="category_id" value="<?=h($categoryEditRow['id'] ?? '')?>">
              </form>
              <form id="<?=h($categoryMoveFormId)?>" method="post" action="operations.php#product-categories" onsubmit="return confirm('確定將這筆細分類與相關產品搬到選定類別？');">
                <input type="hidden" name="action" value="move_product_category_bucket">
                <input type="hidden" name="category_id" value="<?=h($categoryEditRow['id'] ?? '')?>">
              </form>
            <?php endforeach; ?>
            <div class="category-bucket-products"><table><thead><tr><th>選</th><th>排序</th><th>主大綱</th><th>分類大綱</th><th>英文碼</th><th>品牌</th><th>細分類</th><th>條碼前綴</th><th>備註</th><th>排序</th><th>搬移到其他分類</th><th>操作</th></tr></thead><tbody>
              <?php foreach($bucket['rules'] as $c): ?>
                <?php $categoryEditFormId = 'categoryEdit-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($c['id'] ?? '')); ?>
                <?php $categoryMoveFormId = 'categoryMove-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)($c['id'] ?? '')); ?>
                <tr>
                  <td><input type="checkbox" name="category_ids[]" value="<?=h($c['id'] ?? '')?>" form="categoryBulkDeleteForm"></td>
                  <td><input class="category-sort-input" type="number" name="category_sort" value="<?=h($c['sort'] ?? 0)?>" form="<?=h($categoryEditFormId)?>"></td>
                  <td><input name="category_group" value="<?=h($c['group'] ?? '')?>" form="<?=h($categoryEditFormId)?>" required></td>
                  <td><input name="category_type" value="<?=h($c['type'] ?? '')?>" form="<?=h($categoryEditFormId)?>" required></td>
                  <td><input name="category_type_code" value="<?=h(category_type_code($c['type'] ?? '', $c['type_code'] ?? '', $c['barcode_prefix'] ?? ''))?>" maxlength="8" pattern="[A-Za-z0-9]+" placeholder="COM" form="<?=h($categoryEditFormId)?>"></td>
                  <td><input name="category_brand" value="<?=h($c['brand'] ?? '')?>" form="<?=h($categoryEditFormId)?>"></td>
                  <td><input name="category_spec" value="<?=h($c['spec'] ?? '')?>" form="<?=h($categoryEditFormId)?>"></td>
                  <td><input name="category_barcode_prefix" value="<?=h($c['barcode_prefix'] ?? '')?>" maxlength="16" pattern="[A-Za-z0-9]+" placeholder="可稍後設定" form="<?=h($categoryEditFormId)?>"></td>
                  <td><input name="category_note" value="<?=h($c['note'] ?? '')?>" form="<?=h($categoryEditFormId)?>"></td>
                  <td>
                    <div class="inline-actions">
                      <form method="post" class="inline-form"><input type="hidden" name="action" value="move_product_category"><input type="hidden" name="category_id" value="<?=h($c['id'] ?? '')?>"><input type="hidden" name="direction" value="up"><button class="secondary small" type="submit">上移</button></form>
                      <form method="post" class="inline-form"><input type="hidden" name="action" value="move_product_category"><input type="hidden" name="category_id" value="<?=h($c['id'] ?? '')?>"><input type="hidden" name="direction" value="down"><button class="secondary small" type="submit">下移</button></form>
                    </div>
                  </td>
                  <td>
                    <div class="category-move-control">
                      <select name="target_bucket" form="<?=h($categoryMoveFormId)?>" required>
                        <option value="">請選擇目的類別</option>
                        <?php foreach($categoryDestinationBuckets as $destinationBucket): ?>
                          <?php if (($destinationBucket[0] ?? '') === ($c['group'] ?? '') && ($destinationBucket[1] ?? '') === ($c['type'] ?? '')) continue; ?>
                          <option value="<?=h(json_encode([$destinationBucket[0], $destinationBucket[1]], JSON_UNESCAPED_UNICODE))?>"><?=h($destinationBucket[0] . ' / ' . $destinationBucket[1])?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="secondary small" type="submit" form="<?=h($categoryMoveFormId)?>">搬移</button>
                    </div>
                  </td>
                  <td><div class="inline-actions"><button class="primary small" type="submit" form="<?=h($categoryEditFormId)?>">儲存修改</button><form method="post" class="inline-form" onsubmit="return confirm('確定刪除此產品分類？');"><input type="hidden" name="action" value="delete_product_category"><input type="hidden" name="category_id" value="<?=h($c['id'] ?? '')?>"><button class="danger small">刪除</button></form></div></td>
                </tr>
              <?php endforeach; ?>
              <?php if(!$bucket['rules']): ?><tr><td colspan="11" class="muted">這個類別目前沒有分類規則。</td></tr><?php endif; ?>
            </tbody></table></div>
            <h3>此類別產品</h3>
    <div class="category-bucket-products"><table><thead><tr><th>產品</th><th>條碼 / 編號</th><th>品牌</th><th>細分類</th><th>顏色 / 尺碼 / 規格</th><th>庫存</th><th>倉別 / 倉架 / 位置</th></tr></thead><tbody>
              <?php foreach($bucket['products'] as $p): ?>
                <tr>
                  <td><?=h($p['title'] ?? ($p['product_name'] ?? ''))?></td>
                  <td><?=h($p['barcode'] ?? ($p['id'] ?? ''))?></td>
                  <td><?=h($p['category_brand'] ?? '')?></td>
                  <td><?=h($p['category_spec'] ?? '')?></td>
                  <td><?=h(trim(($p['color'] ?? '') . ' / ' . ($p['size'] ?? '') . ' / ' . ($p['spec'] ?? ''), ' /'))?></td>
                  <td><?=h($p['stock_total'] ?? ($p['stock'] ?? 0))?></td>
                  <td><?=h(trim(($p['warehouse_name'] ?? '') . ' / ' . ($p['shelf_code'] ?? '') . ' / ' . ($p['warehouse_location'] ?? ''), ' /'))?></td>
                </tr>
              <?php endforeach; ?>
              <?php if(!$bucket['products']): ?><tr><td colspan="7" class="muted">這個類別目前沒有產品。</td></tr><?php endif; ?>
            </tbody></table></div>
          </div>
        </details>
      <?php endforeach; ?>
      <?php if(!$categoryBuckets): ?><p class="muted">尚未建立產品分類。</p><?php endif; ?>
    </div>
    <script>
    (() => {
      const list = document.querySelector('#product-categories .category-bucket-list');
      const form = document.getElementById('categoryTypeOrderForm');
      if (!list || !form) return;
      let dragged = null;
      list.addEventListener('dragstart', event => {
        const card = event.target.closest('.category-bucket-card');
        if (!card) return;
        dragged = card;
        card.classList.add('dragging');
        event.dataTransfer.effectAllowed = 'move';
      });
      list.addEventListener('dragover', event => {
        const target = event.target.closest('.category-bucket-card');
        if (!dragged || !target || target === dragged || target.dataset.categoryGroup !== dragged.dataset.categoryGroup) return;
        event.preventDefault();
        const rect = target.getBoundingClientRect();
        list.insertBefore(dragged, event.clientY < rect.top + rect.height / 2 ? target : target.nextSibling);
      });
      list.addEventListener('dragend', () => {
        if (!dragged) return;
        const group = dragged.dataset.categoryGroup || '';
        dragged.classList.remove('dragging');
        const orderedTypes = [...list.querySelectorAll('.category-bucket-card')]
          .filter(card => card.dataset.categoryGroup === group)
          .map(card => card.dataset.categoryType);
        dragged = null;
        document.getElementById('categoryTypeOrderGroup').value = group;
        document.getElementById('categoryTypeOrderValue').value = JSON.stringify(orderedTypes);
        form.requestSubmit();
      });
    })();
    </script>
    <div class="table-wrap"><table><thead><tr><th>選</th><th>排序</th><th>群組</th><th>類別</th><th>廠牌</th><th>細分類 / 腳位</th><th>備註</th><th>排序</th><th>操作</th></tr></thead><tbody>
      <?php foreach($categoryShownPage as $c): ?>
        <tr>
          <td><input type="checkbox" name="category_ids[]" value="<?=h($c['id'] ?? '')?>" form="categoryBulkDeleteForm"></td>
          <td><?=h($c['sort'] ?? 0)?></td>
          <td><?=h($c['group'] ?? '')?></td>
          <td><?=h($c['type'] ?? '')?></td>
          <td><?=h($c['brand'] ?? '')?></td>
          <td><?=h($c['spec'] ?? '')?></td>
          <td><?=h($c['note'] ?? '')?></td>
          <td>
            <div class="inline-actions">
              <form method="post" class="inline-form"><input type="hidden" name="action" value="move_product_category"><input type="hidden" name="category_id" value="<?=h($c['id'] ?? '')?>"><input type="hidden" name="direction" value="up"><button class="secondary small" type="submit">上移</button></form>
              <form method="post" class="inline-form"><input type="hidden" name="action" value="move_product_category"><input type="hidden" name="category_id" value="<?=h($c['id'] ?? '')?>"><input type="hidden" name="direction" value="down"><button class="secondary small" type="submit">下移</button></form>
            </div>
          </td>
          <td><form method="post" class="inline-form" onsubmit="return confirm('確定刪除此產品分類？');"><input type="hidden" name="action" value="delete_product_category"><input type="hidden" name="category_id" value="<?=h($c['id'] ?? '')?>"><button class="danger small">刪除</button></form></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$productCategories): ?><tr><td colspan="9" class="muted">尚未建立產品分類。</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($opsCategoryPages > 1): ?>
      <div class="pager"><span>第 <?=h($opsCategoryPage)?> / <?=h($opsCategoryPages)?> 頁，每頁 10 筆；顯示 <?=h(count($categoryShownPage))?> / <?=h($opsCategoryTotal)?> 筆</span><?php if($opsCategoryPage > 1): ?><a class="secondary small" href="<?=h($opsCategoryPageUrl($opsCategoryPage - 1))?>">上一頁</a><?php endif; ?><?php if($opsCategoryPage < $opsCategoryPages): ?><a class="secondary small" href="<?=h($opsCategoryPageUrl($opsCategoryPage + 1))?>">下一頁</a><?php endif; ?></div>
    <?php endif; ?>
  </section>
  <section class="ops-card ops-tab shopee-workspace" id="shopee-workspace">
    <?php
      $hubPlatform = marketplace_current_platform();
      $hubChannel = marketplace_channel($hubPlatform);
      $hubAuthorized = marketplace_is_authorized($hubPlatform);
      $hubQuery = trim((string)($_GET['hub_q'] ?? ($_GET['shopee_q'] ?? '')));
      $hubAction = marketplace_hub_query();
      $hubDraftIndex = marketplace_drafts_index($marketplaceListingDrafts);
      $hubCandidates = $marketplaceMallCandidates ?? [];
      $hubProducts = array_values(array_filter($products, function($product) use ($hubQuery) {
          if ($hubQuery === '') return true;
          $haystack = implode(' ', [
              $product['id'] ?? '', $product['barcode'] ?? '', $product['title'] ?? '',
              $product['category_group'] ?? '', $product['category_type'] ?? '',
              $product['category_brand'] ?? '', $product['category_spec'] ?? ''
          ]);
          return mb_stripos($haystack, $hubQuery) !== false;
      }));
      $hubProducts = array_slice($hubProducts, 0, 10);
      $hubReadyCount = count(array_filter($products, function($product) {
          return trim((string)($product['title'] ?? '')) !== ''
              && trim((string)($product['image'] ?? '')) !== ''
              && stock_available($product) > 0
              && trim((string)($product['category_type'] ?? '')) !== '';
      }));
      $hubMappings = array_values(array_filter($marketplaceCategoryMappings, function($row) use ($hubPlatform) {
          return marketplace_normalize_platform($row['platform'] ?? '') === $hubPlatform;
      }));
      $hubDrafts = array_values(array_filter($marketplaceListingDrafts, function($row) use ($hubPlatform) {
          return marketplace_normalize_platform($row['platform'] ?? '') === $hubPlatform;
      }));
      $hubUnfiledCount = count(array_filter($hubMappings, function($row) {
          return marketplace_mapping_is_unfiled($row);
      }));
      $hubMapFilter = trim((string)($_GET['map'] ?? 'all'));
      if ($hubMapFilter === 'unfiled') {
          $hubMappings = array_values(array_filter($hubMappings, function($row) { return marketplace_mapping_is_unfiled($row); }));
      } elseif ($hubMapFilter === 'mapped') {
          $hubMappings = array_values(array_filter($hubMappings, function($row) { return !marketplace_mapping_is_unfiled($row); }));
      }
      $hubMallMatched = 0;
      foreach ($hubProducts as $hubProbe) {
          $mallHit = marketplace_match_mall($hubProbe, $hubCandidates);
          if (!empty($mallHit['matched'])) $hubMallMatched++;
      }
      $hubPreviewProducts = array_slice(array_values(array_filter($products, function($product) {
          return trim((string)($product['title'] ?? '')) !== '' && trim((string)($product['image'] ?? '')) !== '';
      })), 0, 4);
      $hubMenuTypes = [];
      foreach ($productCategories as $category) {
          $type = trim((string)($category['type'] ?? ''));
          if ($type !== '') $hubMenuTypes[$type] = true;
      }
      $hubCred = marketplace_credentials_for($hubPlatform);
      $hubStatusText = $hubPlatform === 'yahoo_auction_tw'
          ? '無公開上架 API，請下載大量刊登助手 CSV'
          : ($hubAuthorized ? '已授權，可送出上架／同步庫存' : '尚未授權 Open API');
      $hubGroups = array_values(array_unique(array_filter(array_map(function($row){ return trim((string)($row['group'] ?? '')); }, $productCategories))));
    ?>
    <style>
      .shopee-workspace{--channel:<?=h($hubChannel['color'])?>;--channel-soft:<?=h($hubChannel['soft'])?>;--brand:#0f766e;--ink:#172033;--line:#dbe3ea;color:var(--ink)}
      .shopee-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;align-items:center;padding:22px;border-left:5px solid var(--channel);background:#f8fafc}
      .shopee-hero h2{margin:0 0 6px;font-size:24px}.shopee-hero p{margin:0;color:#526071}.shopee-channel-status{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.shopee-status-dot{width:10px;height:10px;border-radius:50%;background:<?= $hubAuthorized ? '#059669' : '#d97706' ?>}.shopee-status-pill{border:1px solid #f5c6b8;background:var(--channel-soft);color:var(--channel);border-radius:999px;padding:7px 10px;font-weight:800;font-size:13px}.shopee-open{background:var(--channel)!important;border-color:var(--channel)!important;color:#fff!important;text-decoration:none}
      .hub-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px}.hub-tabs a{border:1px solid var(--line);padding:8px 14px;border-radius:999px;font-weight:800;text-decoration:none;color:#334155;background:#fff}.hub-tabs a.is-on{background:var(--channel);border-color:var(--channel);color:#fff}
      .shopee-metrics{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin:16px 0}.shopee-metric{border:1px solid var(--line);padding:14px;background:#fff}.shopee-metric b{display:block;font-size:24px}.shopee-metric span{font-size:13px;color:#64748b}
      .shopee-band{margin-top:18px;padding-top:18px;border-top:1px solid var(--line)}.shopee-band h3{margin:0 0 4px}.shopee-band-head{display:flex;justify-content:space-between;gap:12px;align-items:end;flex-wrap:wrap;margin-bottom:12px}.shopee-band-head p{margin:0;color:#64748b}
      .shopee-map-form{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr));gap:10px;align-items:end}.shopee-map-form label,.hub-cred-form label,.hub-card label{display:grid;gap:5px;font-weight:700}.shopee-map-form .wide{grid-column:span 2}.shopee-map-form input,.shopee-map-form select,.hub-cred-form input,.hub-card input,.hub-card textarea{width:100%}
      .hub-card{display:grid;grid-template-columns:62px minmax(0,1fr);gap:12px;padding:14px 0;border-bottom:1px solid var(--line);align-items:start}.hub-card img,.hub-card .image-empty{width:62px;height:62px;object-fit:contain;border:1px solid var(--line);background:#fff}.hub-card .image-empty{display:grid;place-items:center;color:#94a3b8;font-size:12px}.hub-card textarea{height:62px;resize:vertical}.hub-meta{display:flex;flex-wrap:wrap;gap:8px;margin:6px 0;font-size:12px;color:#475569}.hub-meta b{color:#0f172a}.hub-pills{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 10px}.hub-pill{border:1px solid var(--line);border-radius:999px;padding:3px 8px;font-size:12px;font-weight:800;background:#fff}.hub-pill.is-draft{color:#0f766e}.hub-pill.is-live{color:#0369a1}.hub-pill.is-warn{color:#b45309;border-color:#fbbf24;background:#fffbeb}.hub-fields{display:grid;grid-template-columns:110px 95px 110px minmax(180px,1fr);gap:8px;margin:8px 0}.hub-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:8px}.shopee-draft-badge{color:var(--brand);font-weight:800;font-size:12px}
      .hub-cred-form{display:grid;grid-template-columns:repeat(3,minmax(160px,1fr));gap:10px;align-items:end}
      .hub-map-edit{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.hub-map-edit input{min-width:140px;flex:1}
      .shopee-store-preview{border:1px solid var(--line);background:#f5f7f9}.shopee-store-banner{padding:26px;background:#162033;color:#fff;display:flex;justify-content:space-between;align-items:end;gap:18px}.shopee-store-banner h3{font-size:28px;margin:0}.shopee-store-banner p{margin:6px 0 0;color:#d9e2ea}.shopee-store-banner strong{color:#69d2c6}.shopee-category-strip{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));background:#fff;border-bottom:1px solid var(--line)}.shopee-category-strip span{padding:14px 8px;text-align:center;border-right:1px solid var(--line);font-weight:800}.shopee-preview-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:16px}.shopee-preview-item{background:#fff;border:1px solid var(--line);padding:10px}.shopee-preview-item img,.shopee-preview-item .image-empty{width:100%;aspect-ratio:1;object-fit:contain;background:#fff;display:grid;place-items:center;color:#94a3b8}.shopee-preview-item b{display:block;margin-top:8px;line-height:1.35;min-height:38px}.shopee-preview-item small{color:#64748b}.shopee-preview-price{display:flex;justify-content:space-between;align-items:center;margin-top:8px;color:var(--channel);font-weight:900}
      @media(max-width:900px){.shopee-hero{grid-template-columns:1fr}.shopee-channel-status{justify-content:flex-start}.shopee-metrics,.hub-fields,.hub-cred-form{grid-template-columns:repeat(2,1fr)}.shopee-map-form{grid-template-columns:1fr 1fr}.shopee-category-strip{grid-template-columns:repeat(3,1fr)}.shopee-preview-grid{grid-template-columns:repeat(2,1fr)}}
      @media(max-width:520px){.shopee-metrics,.shopee-map-form,.shopee-preview-grid,.hub-fields,.hub-cred-form{grid-template-columns:1fr}.shopee-map-form .wide{grid-column:auto}.shopee-store-banner{align-items:start;flex-direction:column}.shopee-category-strip{grid-template-columns:repeat(2,1fr)}}
    </style>
    <nav class="hub-tabs" aria-label="通路切換">
      <?php foreach (marketplace_channels() as $tabId => $tab): $tabHref = marketplace_hub_query(['channel' => $tabId, 'hub_q' => $hubQuery]); ?>
        <a class="<?= $tabId === $hubPlatform ? 'is-on' : '' ?>" href="<?=h($tabHref)?>"><?=h($tab['label'])?></a>
      <?php endforeach; ?>
    </nav>
    <div class="shopee-hero">
      <div>
        <h2>多通路上架工作台</h2>
        <p>同一顆後台產品主檔，對官網參考型錄 SKU，再分別做<?=h($hubChannel['label'])?>草稿。庫存以後台可用量為準；沒金鑰不會假裝已上架。</p>
      </div>
      <div class="shopee-channel-status">
        <span class="shopee-status-dot" aria-hidden="true"></span>
        <span class="shopee-status-pill"><?=h($hubStatusText)?></span>
        <a class="primary shopee-open" href="<?=h($hubChannel['seller_url'])?>" target="_blank" rel="noopener"><?=h($hubChannel['seller_label'])?></a>
        <?php if ($hubPlatform === 'yahoo_auction_tw'): ?><a class="secondary" href="<?=h($hubChannel['help_url'])?>" target="_blank" rel="noopener">大量刊登助手說明</a><?php endif; ?>
      </div>
    </div>
    <div class="shopee-metrics">
      <div class="shopee-metric"><b><?=h(count($products))?></b><span>後台產品總數</span></div>
      <div class="shopee-metric"><b><?=h($hubReadyCount)?></b><span>圖片、分類、可用庫存齊全</span></div>
      <div class="shopee-metric"><b><?=h(count($hubMappings))?></b><span><?=h($hubChannel['label'])?>分類對照</span></div>
      <div class="shopee-metric"><b><?=h(count($hubDrafts))?></b><span><?=h($hubChannel['label'])?>上架草稿</span></div>
      <div class="shopee-metric"><b><?=h($hubUnfiledCount)?></b><span><?=h($hubChannel['label'])?>未歸檔</span></div>
      <div class="shopee-metric"><b><?=h($hubMallMatched)?></b><span>本頁已對上官網 SKU</span></div>
    </div>

    <div class="shopee-band">
      <div class="shopee-band-head">
        <div>
          <h3>分類對照</h3>
          <p>已依寶輝分類大綱帶入<?=h($hubChannel['label'])?>與官網商城對應；確定的先填好，不確定的標「未歸檔」給你自己帶入。分類 ID 有金鑰後再補。</p>
        </div>
        <div class="hub-pills">
          <a class="hub-pill <?= $hubMapFilter === 'all' ? 'is-live' : '' ?>" href="<?=h(marketplace_hub_query(['map' => 'all']))?>">全部</a>
          <a class="hub-pill <?= $hubMapFilter === 'mapped' ? 'is-live' : '' ?>" href="<?=h(marketplace_hub_query(['map' => 'mapped']))?>">已對照</a>
          <a class="hub-pill <?= $hubMapFilter === 'unfiled' ? 'is-warn' : '' ?>" href="<?=h(marketplace_hub_query(['map' => 'unfiled']))?>">未歸檔 <?=h($hubUnfiledCount)?></a>
        </div>
      </div>
      <form method="post" action="<?=h($hubAction)?>" class="shopee-map-form">
        <?php if (!empty($isEmbed)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <input type="hidden" name="action" value="save_marketplace_category_mapping">
        <input type="hidden" name="platform" value="<?=h($hubPlatform)?>">
        <label>主大綱<select name="source_group" required><option value="">請選擇</option><?php foreach ($hubGroups as $value): ?><option><?=h($value)?></option><?php endforeach; ?></select></label>
        <label>分類大綱<input name="source_type" list="computerCategoryTypeSuggestions" required placeholder="例如：顯示卡"></label>
        <label>品牌<input name="source_brand" list="computerBrandSuggestions" placeholder="可留空，代表整個類別"></label>
        <label>細分類<input name="source_spec" placeholder="可留空"></label>
        <label class="wide"><?=h($hubChannel['label'])?>分類路徑<input name="platform_category_path" placeholder="確定就填路徑；不確定請留空或填未歸檔"></label>
        <label><?=h($hubChannel['label'])?>分類 ID<input name="platform_category_id" placeholder="<?= $hubPlatform === 'yahoo_auction_tw' ? '奇摩類別代號' : '取得 API 後填入' ?>"></label>
        <button class="primary" type="submit">儲存／帶入分類</button>
      </form>
      <div class="table-wrap" style="margin-top:12px"><table><thead><tr><th>寶輝分類</th><th>官網商城</th><th><?=h($hubChannel['label'])?>分類</th><th>分類 ID</th><th>狀態</th><th>自己帶入</th></tr></thead><tbody>
      <?php foreach ($hubMappings as $mapping): $unfiled = marketplace_mapping_is_unfiled($mapping); ?>
        <tr>
          <td><?=h(implode(' / ', array_filter([$mapping['source_group'] ?? '', $mapping['source_type'] ?? '', $mapping['source_brand'] ?? '', $mapping['source_spec'] ?? ''])))?></td>
          <td><?=h(($mapping['mall_category_path'] ?? '') !== '' ? $mapping['mall_category_path'] : '—')?></td>
          <td><?=h($unfiled ? '未歸檔' : ($mapping['platform_category_path'] ?? ''))?></td>
          <td><?=h(($mapping['platform_category_id'] ?? '') !== '' ? $mapping['platform_category_id'] : '待取得')?></td>
          <td><?php if ($unfiled): ?><span class="hub-pill is-warn">未歸檔</span><?php else: ?><span class="shopee-draft-badge"><?=h($mapping['status'] ?? '已對照')?></span><?php endif; ?></td>
          <td>
            <form method="post" action="<?=h($hubAction)?>" class="inline-form hub-map-edit">
              <?php if (!empty($isEmbed)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
              <input type="hidden" name="action" value="save_marketplace_category_mapping">
              <input type="hidden" name="platform" value="<?=h($hubPlatform)?>">
              <input type="hidden" name="mapping_id" value="<?=h($mapping['id'] ?? '')?>">
              <input type="hidden" name="source_group" value="<?=h($mapping['source_group'] ?? '')?>">
              <input type="hidden" name="source_type" value="<?=h($mapping['source_type'] ?? '')?>">
              <input type="hidden" name="source_brand" value="<?=h($mapping['source_brand'] ?? '')?>">
              <input type="hidden" name="source_spec" value="<?=h($mapping['source_spec'] ?? '')?>">
              <input type="hidden" name="mall_category_path" value="<?=h($mapping['mall_category_path'] ?? '')?>">
              <input name="platform_category_path" value="<?=h($unfiled ? '' : ($mapping['platform_category_path'] ?? ''))?>" placeholder="貼上<?=h($hubChannel['label'])?>分類路徑">
              <input name="platform_category_id" value="<?=h($mapping['platform_category_id'] ?? '')?>" placeholder="分類 ID">
              <button class="secondary small" type="submit">帶入</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$hubMappings): ?><tr><td colspan="6" class="muted">此篩選沒有分類對照。</td></tr><?php endif; ?>
      </tbody></table></div>
    </div>

    <div class="shopee-band">
      <div class="shopee-band-head">
        <div><h3>商品上架草稿</h3><p>每一列顯示後台庫存、官網 SKU、三通路狀態。通路庫存不可超過後台可用量。</p></div>
        <form method="get" action="operations.php#shopee-workspace">
          <?php if (!empty($isEmbed)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
          <input type="hidden" name="tab" value="shopee-workspace">
          <input type="hidden" name="channel" value="<?=h($hubPlatform)?>">
          <input name="hub_q" value="<?=h($hubQuery)?>" placeholder="產品名稱、條碼、品牌">
          <button class="secondary" type="submit">搜尋產品</button>
        </form>
      </div>
      <?php if ($hubPlatform === 'yahoo_auction_tw'): ?>
        <form method="post" action="<?=h($hubAction)?>" style="margin-bottom:12px">
          <?php if (!empty($isEmbed)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
          <input type="hidden" name="action" value="export_yahoo_auction_csv">
          <input type="hidden" name="platform" value="yahoo_auction_tw">
          <?php foreach ($hubProducts as $exportProduct): ?>
            <input type="hidden" name="yahoo_export_ids[]" value="<?=h($exportProduct['id'] ?? '')?>">
          <?php endforeach; ?>
          <button class="primary" type="submit">匯出本頁大量刊登助手 CSV</button>
          <span class="muted">賣家貨號填後台產品編號／條碼；刊登後把拍賣網址貼回同一筆草稿。</span>
        </form>
      <?php endif; ?>
      <?php foreach ($hubProducts as $product):
        $pid = (string)($product['id'] ?? '');
        $draft = $hubDraftIndex[$hubPlatform][$pid] ?? [];
        $available = stock_available($product);
        $mall = marketplace_match_mall($product, $hubCandidates);
        $flag = marketplace_stock_flag($product, $draft);
        $copyText = trim(($draft['title'] ?? ($product['title'] ?? '')) . "\n" . ($draft['description'] ?? ($product['description'] ?? '')));
      ?>
        <div class="hub-card">
          <?php if (!empty($product['image'])): ?><img src="<?=h($product['image'])?>" alt="<?=h($product['title'] ?? '')?>"><?php else: ?><span class="image-empty">無主圖</span><?php endif; ?>
          <div>
            <form method="post" action="<?=h($hubAction)?>">
              <?php if (!empty($isEmbed)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
              <input type="hidden" name="platform" value="<?=h($hubPlatform)?>">
              <input type="hidden" name="product_id" value="<?=h($pid)?>">
              <label class="shopee-product-title">商品標題<input name="listing_title" maxlength="120" value="<?=h($draft['title'] ?? ($product['title'] ?? ''))?>" required></label>
              <div class="hub-meta">
                <span>後台編號 <b><?=h($pid)?></b></span>
                <span>條碼 <b><?=h($product['barcode'] ?? '—')?></b></span>
                <span>可用庫存 <b><?=h($available)?></b></span>
                <span>後台售價 <b><?=money($product['selling_price'] ?? ($product['start_price'] ?? 0))?></b></span>
                <?php if (!empty($mall['matched'])): ?>
                  <span>官網 <b>已在前台 <?=h($mall['sku'])?></b><?php if (!empty($mall['department']) || !empty($mall['category'])): ?>　<?=h(implode(' > ', array_filter([$mall['department'] ?? '', $mall['category'] ?? ''])))?><?php endif; ?>　<a href="<?=h($mall['url'])?>" target="_blank" rel="noopener">開電腦商城</a></span>
                <?php elseif (!empty($mall['candidate'])): ?>
                  <span>官網 <b>僅後台／競標庫</b>（已列候選 <?=h($mall['sku'])?>）</span>
                <?php else: ?>
                  <span>官網 <b>僅後台／競標庫</b></span>
                <?php endif; ?>
                <?php
                  $shopeeCat = marketplace_product_category_status($product, $marketplaceCategoryMappings, 'shopee_tw');
                  $rutenCat = marketplace_product_category_status($product, $marketplaceCategoryMappings, 'ruten_tw');
                ?>
                <span>蝦皮分類 <b><?=h($shopeeCat['path'])?></b></span>
                <span>露天分類 <b><?=h($rutenCat['path'])?></b></span>
              </div>
              <div class="hub-pills">
                <?php foreach (marketplace_channels() as $statusPlatform => $statusChannel):
                  $statusDraft = $hubDraftIndex[$statusPlatform][$pid] ?? [];
                  $statusLabel = $statusDraft ? (($statusDraft['status'] ?? '草稿') . (!empty($statusDraft['platform_item_id']) ? ' #' . $statusDraft['platform_item_id'] : '')) : '未建';
                  $pillClass = !$statusDraft ? '' : ((in_array(($statusDraft['status'] ?? ''), ['已刊登', '上架中'], true) || !empty($statusDraft['platform_item_id'])) ? 'is-live' : 'is-draft');
                ?>
                  <span class="hub-pill <?=h($pillClass)?>"><?=h($statusChannel['short'])?> <?=h($statusLabel)?><?php if (!empty($statusDraft['last_synced_at'])): ?>　<?=h(str_replace('T', ' ', substr((string)$statusDraft['last_synced_at'], 0, 16)))?><?php endif; ?></span>
                <?php endforeach; ?>
                <?php if ($flag !== ''): ?><span class="hub-pill is-warn"><?=h($flag)?></span><?php endif; ?>
                <?php if (!empty($shopeeCat['unfiled']) || !empty($rutenCat['unfiled'])): ?><span class="hub-pill is-warn">分類未歸檔</span><?php endif; ?>
              </div>
              <div class="hub-fields">
                <label>通路售價<input name="listing_price" type="number" min="0" step="1" value="<?=h($draft['price'] ?? ($product['selling_price'] ?? ($product['start_price'] ?? 0)))?>"></label>
                <label>通路庫存<input name="listing_stock" type="number" min="0" max="<?=h($available)?>" value="<?=h($draft['stock'] ?? $available)?>"></label>
                <label>重量 kg<input name="weight_kg" type="number" min="0" step="0.01" value="<?=h($draft['weight_kg'] ?? '')?>"></label>
                <label>商品說明<textarea name="listing_description" placeholder="產品重點、規格、保固、出貨說明"><?=h($draft['description'] ?? ($product['description'] ?? ''))?></textarea></label>
              </div>
              <div class="hub-actions">
                <button class="primary" type="submit" name="action" value="save_marketplace_listing_draft"><?= $draft ? '更新草稿' : '建立草稿' ?></button>
                <button class="secondary" type="button" data-copy-listing="<?=h($copyText)?>">複製文案</button>
                <?php if ($hubPlatform !== 'yahoo_auction_tw' && $hubAuthorized): ?>
                  <button class="primary" type="submit" name="action" value="publish_marketplace_listing">送出上架</button>
                  <button class="secondary" type="submit" name="action" value="sync_marketplace_stock">同步庫存</button>
                <?php endif; ?>
              </div>
            </form>
            <?php if (empty($mall['matched']) && empty($mall['candidate'])): ?>
              <form method="post" action="<?=h($hubAction)?>" class="hub-actions">
                <?php if (!empty($isEmbed)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
                <input type="hidden" name="action" value="add_mall_catalog_candidate">
                <input type="hidden" name="platform" value="<?=h($hubPlatform)?>">
                <input type="hidden" name="product_id" value="<?=h($pid)?>">
                <input type="hidden" name="candidate_sku" value="<?=h($product['barcode'] ?? $pid)?>">
                <button class="secondary small" type="submit">加入官網參考型錄候選</button>
              </form>
            <?php endif; ?>
            <form method="post" action="<?=h($hubAction)?>" class="hub-actions">
              <?php if (!empty($isEmbed)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
              <input type="hidden" name="action" value="save_marketplace_listing_link">
              <input type="hidden" name="platform" value="<?=h($hubPlatform)?>">
              <input type="hidden" name="product_id" value="<?=h($pid)?>">
              <input type="hidden" name="listing_title" value="<?=h($draft['title'] ?? ($product['title'] ?? ''))?>">
              <input type="hidden" name="listing_price" value="<?=h($draft['price'] ?? ($product['selling_price'] ?? 0))?>">
              <input type="hidden" name="listing_stock" value="<?=h($draft['stock'] ?? $available)?>">
              <label>平台商品編號<input name="platform_item_id" value="<?=h($draft['platform_item_id'] ?? '')?>" placeholder="刊登後填回"></label>
              <label>平台網址<input name="platform_url" value="<?=h($draft['platform_url'] ?? '')?>" placeholder="奇摩／露天／蝦皮商品網址"></label>
              <button class="secondary small" type="submit">回填連棟</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$hubProducts): ?><p class="muted">找不到符合的產品。</p><?php endif; ?>
    </div>

    <div class="shopee-band">
      <div class="shopee-band-head"><div><h3>通路憑證</h3><p>金鑰只寫入伺服器 private 目錄，不進 git。留空的欄位不會覆蓋已存密鑰。</p></div></div>
      <?php if ($hubPlatform === 'yahoo_auction_tw'): ?>
        <p class="muted">奇摩拍賣沒有公開賣家上架 API。官方途徑是大量刊登助手 CSV；刊登後把商品編號／網址貼回草稿即可連棟。</p>
      <?php else: ?>
        <form method="post" action="<?=h($hubAction)?>" class="hub-cred-form">
          <?php if (!empty($isEmbed)): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
          <input type="hidden" name="action" value="save_marketplace_credentials">
          <input type="hidden" name="platform" value="<?=h($hubPlatform)?>">
          <?php if ($hubPlatform === 'shopee_tw'): ?>
            <label>Partner ID<input name="shopee_partner_id" value="<?=h($hubCred['partner_id'] ?? '')?>" autocomplete="off"></label>
            <label>Partner Key<input name="shopee_partner_key" type="password" placeholder="<?=h(($hubCred['partner_key'] ?? '') !== '' ? marketplace_mask_secret($hubCred['partner_key']) : '尚未填寫')?>" autocomplete="new-password"></label>
            <label>Shop ID<input name="shopee_shop_id" value="<?=h($hubCred['shop_id'] ?? '')?>" autocomplete="off"></label>
            <label>Access Token<input name="shopee_access_token" type="password" placeholder="<?=h(($hubCred['access_token'] ?? '') !== '' ? marketplace_mask_secret($hubCred['access_token']) : '尚未填寫')?>" autocomplete="new-password"></label>
            <label>Refresh Token<input name="shopee_refresh_token" type="password" placeholder="<?=h(($hubCred['refresh_token'] ?? '') !== '' ? marketplace_mask_secret($hubCred['refresh_token']) : '選填')?>" autocomplete="new-password"></label>
          <?php else: ?>
            <label>API Key<input name="ruten_api_key" value="<?=h($hubCred['api_key'] ?? '')?>" autocomplete="off"></label>
            <label>Secret Key<input name="ruten_secret_key" type="password" placeholder="<?=h(($hubCred['secret_key'] ?? '') !== '' ? marketplace_mask_secret($hubCred['secret_key']) : '尚未填寫')?>" autocomplete="new-password"></label>
            <label>Salt Key<input name="ruten_salt_key" type="password" placeholder="<?=h(($hubCred['salt_key'] ?? '') !== '' ? marketplace_mask_secret($hubCred['salt_key']) : '尚未填寫')?>" autocomplete="new-password"></label>
            <label>賣場自訂分類 ID<input name="ruten_store_class_id" value="<?=h($hubCred['store_class_id'] ?? '')?>" placeholder="上架必填"></label>
            <label>所在地區代碼<input name="ruten_location" value="<?=h($hubCred['location'] ?? '05')?>" placeholder="例如 05"></label>
          <?php endif; ?>
          <button class="primary" type="submit">儲存憑證</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="shopee-band">
      <div class="shopee-band-head"><div><h3><?=h($hubChannel['label'])?>賣場版面預覽</h3><p>預覽用版型，不會直接變更目前賣場。</p></div></div>
      <div class="shopee-store-preview">
        <div class="shopee-store-banner"><div><h3>寶輝科技</h3><p>電腦零組件・維修服務・網路儲存設備</p></div><strong>專業選品｜清楚規格｜售後服務</strong></div>
        <div class="shopee-category-strip"><?php foreach (array_slice(array_keys($hubMenuTypes), 0, 6) as $menuType): ?><span><?=h($menuType)?></span><?php endforeach; ?><?php if (!$hubMenuTypes): ?><span>處理器</span><span>主機板</span><span>顯示卡</span><span>記憶體</span><span>SSD 硬碟</span><span>螢幕</span><?php endif; ?></div>
        <div class="shopee-preview-grid">
          <?php foreach ($hubPreviewProducts as $product): ?>
            <article class="shopee-preview-item"><?php if (!empty($product['image'])): ?><img src="<?=h($product['image'])?>" alt="<?=h($product['title'] ?? '')?>"><?php else: ?><span class="image-empty">圖片待補</span><?php endif; ?><b><?=h($product['title'] ?? '')?></b><small><?=h(implode(' / ', array_filter([$product['category_brand'] ?? '', $product['category_spec'] ?? '', $product['color'] ?? '', $product['size'] ?? ''])))?></small><div class="shopee-preview-price"><span><?=money($product['selling_price'] ?? ($product['start_price'] ?? 0))?></span><small>可用 <?=h(stock_available($product))?></small></div></article>
          <?php endforeach; ?>
          <?php if (!$hubPreviewProducts): ?><p class="muted">產品補齊授權主圖後，會在此顯示賣場商品卡預覽。</p><?php endif; ?>
        </div>
      </div>
    </div>
    <script>
      document.querySelectorAll('[data-copy-listing]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var text = btn.getAttribute('data-copy-listing') || '';
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { btn.textContent = '已複製'; setTimeout(function () { btn.textContent = '複製文案'; }, 1200); });
          }
        });
      });
    </script>
  </section>
  <?php
    $erpToday = date('Y-m-d');
    $erpMonth = date('Y-m');
    $erpDocs = [];
    foreach (($documentWorkflows ?? []) as $wf) {
      $erpDocs[] = [
        'type' => ops_document_type_label($wf['document_type'] ?? '流程單據'),
        'no' => $wf['formal_document_no'] ?? ($wf['workflow_no'] ?? ($wf['id'] ?? '')),
        'target' => $wf['target'] ?? '',
        'amount' => (float)($wf['amount'] ?? 0),
        'status' => $wf['status'] ?? '草稿',
        'time' => $wf['updated_at'] ?? ($wf['created_at'] ?? ''),
        'source' => '單據流程中心',
        'summary' => $wf['summary'] ?? '',
        'link' => 'draft-center',
      ];
    }
    foreach (($stockMovements ?? []) as $mv) {
      $qty = (float)($mv['qty'] ?? 0);
      $cost = (float)($mv['unit_cost'] ?? 0);
      $movementAmount = isset($mv['amount']) ? (float)$mv['amount'] : (isset($mv['total_amount']) ? (float)$mv['total_amount'] : ($qty * $cost));
      $movementType = trim((string)($mv['source_doc_type'] ?? ($mv['type'] ?? '進貨單據')));
      if ($movementType === '') $movementType = '進貨單據';
      $movementType = ops_document_type_label($movementType);
      $erpDocs[] = [
        'type' => $movementType,
        'no' => $mv['document_no'] ?? ($mv['source_doc_no'] ?? ($mv['doc_no'] ?? ($mv['id'] ?? ''))),
        'target' => $mv['party_name'] ?? ($mv['supplier_name'] ?? ($mv['product_title'] ?? '')),
        'amount' => $movementAmount,
        'status' => $mv['status'] ?? '入庫完成',
        'time' => $mv['document_date'] ?? ($mv['date'] ?? ($mv['created_at'] ?? '')),
        'source' => $mv['source'] ?? '',
        'summary' => $mv['summary'] ?? ($mv['note'] ?? ''),
        'link' => 'stock-in',
      ];
    }
    foreach (($deliveryNotes ?? []) as $dn) {
      $erpDocs[] = [
        'type' => '銷售出貨單',
        'no' => $dn['delivery_no'] ?? ($dn['id'] ?? ''),
        'target' => $dn['customer_name'] ?? ($dn['member_name'] ?? ''),
        'amount' => (float)($dn['total_amount'] ?? $dn['amount'] ?? 0),
        'status' => $dn['status'] ?? '已建立',
        'time' => $dn['created_at'] ?? ($dn['delivery_date'] ?? ''),
        'source' => '',
        'summary' => $dn['note'] ?? '',
        'link' => 'customer-shipping',
      ];
    }
    foreach (($paymentRecords ?? []) as $pay) {
      $erpDocs[] = [
        'type' => '付款紀錄',
        'no' => $pay['payment_no'] ?? ($pay['id'] ?? ''),
        'target' => $pay['customer_name'] ?? ($pay['member_name'] ?? ($pay['supplier_name'] ?? '')),
        'amount' => (float)($pay['amount'] ?? 0),
        'status' => $pay['status'] ?? '已付款',
        'time' => $pay['paid_at'] ?? ($pay['payment_date'] ?? ($pay['created_at'] ?? '')),
        'source' => '',
        'summary' => $pay['note'] ?? '',
        'link' => 'finance-collection',
      ];
    }
    foreach (($repairDocuments ?? []) as $rp) {
      $erpDocs[] = [
        'type' => '維修單據',
        'no' => $rp['repair_no'] ?? ($rp['id'] ?? ''),
        'target' => $rp['customer_name'] ?? ($rp['customer'] ?? ''),
        'amount' => (float)($rp['amount'] ?? 0),
        'status' => $rp['repair_status'] ?? ($rp['status'] ?? '待處理'),
        'time' => $rp['created_at'] ?? '',
        'source' => '',
        'summary' => $rp['note'] ?? '',
        'link' => 'repair-documents',
      ];
    }
    foreach (($returns ?? []) as $rt) {
      $erpDocs[] = [
        'type' => '銷貨退回',
        'no' => $rt['return_no'] ?? ($rt['id'] ?? ''),
        'target' => $rt['member_name'] ?? '',
        'amount' => (float)($rt['refund_amount'] ?? 0),
        'status' => $rt['status'] ?? '申請中',
        'time' => $rt['updated_at'] ?? ($rt['created_at'] ?? ''),
        'source' => '',
        'summary' => $rt['reason'] ?? ($rt['note'] ?? ''),
        'link' => 'returns',
      ];
    }
    foreach (($collectionReceipts ?? []) as $cr) {
      $erpDocs[] = [
        'type' => '收款單',
        'no' => $cr['receipt_no'] ?? ($cr['id'] ?? ''),
        'target' => $cr['customer_name'] ?? ($cr['member_name'] ?? ''),
        'amount' => (float)($cr['amount'] ?? 0),
        'status' => $cr['status'] ?? '已建立',
        'time' => $cr['created_at'] ?? '',
        'source' => '',
        'summary' => $cr['note'] ?? '',
        'link' => 'finance-collection',
      ];
    }
    foreach (($billingRequests ?? []) as $request) {
      $erpDocs[] = [
        'type' => '請款單',
        'no' => $request['request_no'] ?? ($request['id'] ?? ''),
        'target' => $request['customer_name'] ?? '',
        'amount' => (float)($request['total_amount'] ?? 0),
        'status' => $request['status'] ?? '待請款',
        'time' => $request['created_at'] ?? '',
        'source' => '',
        'summary' => $request['note'] ?? '',
        'link' => 'finance-request',
      ];
    }
    foreach (($badDebts ?? []) as $case) {
      $erpDocs[] = [
        'type' => '呆帳案件',
        'no' => $case['case_no'] ?? ($case['id'] ?? ''),
        'target' => $case['customer_name'] ?? '',
        'amount' => (float)($case['remaining_amount'] ?? $case['amount'] ?? 0),
        'status' => $case['status'] ?? '待催收',
        'time' => $case['created_at'] ?? '',
        'source' => '',
        'summary' => $case['note'] ?? '',
        'link' => 'finance-bad-debt',
      ];
    }
    foreach (($fixedExpensePayments ?? []) as $payment) {
      $erpDocs[] = [
        'type' => '固定開支付款',
        'no' => $payment['payment_no'] ?? ($payment['id'] ?? ''),
        'target' => trim((string)($payment['supplier_name'] ?? '')) !== '' ? ($payment['supplier_name'] ?? '') : ($payment['expense_name'] ?? ''),
        'amount' => (float)($payment['amount'] ?? 0),
        'status' => $payment['status'] ?? '待付款',
        'time' => $payment['paid_date'] ?? ($payment['due_date'] ?? ($payment['created_at'] ?? '')),
        'source' => '',
        'summary' => $payment['note'] ?? '',
        'link' => 'finance-fixed-expense',
      ];
    }
    foreach (($fixedAssets ?? []) as $asset) {
      $assetSnapshot = fixed_asset_depreciation_snapshot($asset, date('Y-m-d'));
      $erpDocs[] = [
        'type' => '固定資產卡',
        'no' => $asset['asset_no'] ?? ($asset['id'] ?? ''),
        'target' => $asset['asset_name'] ?? '',
        'amount' => (float)($assetSnapshot['book_value'] ?? $asset['acquisition_cost'] ?? 0),
        'status' => $asset['status'] ?? '使用中',
        'time' => $asset['updated_at'] ?? ($asset['created_at'] ?? ''),
        'source' => $asset['source_document_key'] ?? '',
        'summary' => $asset['note'] ?? '',
        'link' => 'finance-fixed-asset',
      ];
    }
    foreach (($mobileAssetMovements ?? []) as $movement) {
      $erpDocs[] = [
        'type' => '移動資產異動',
        'no' => $movement['movement_no'] ?? ($movement['id'] ?? ''),
        'target' => trim((string)($movement['asset_name'] ?? '') . ' / ' . (string)($movement['to_holder'] ?? ''), ' /'),
        'amount' => 0,
        'status' => $movement['status_after'] ?? ($movement['movement_type'] ?? ''),
        'time' => $movement['movement_date'] ?? ($movement['created_at'] ?? ''),
        'source' => '',
        'summary' => $movement['note'] ?? '',
        'link' => 'finance-mobile-asset',
      ];
    }
    foreach (($inventoryCounts ?? []) as $ic) {
      $erpDocs[] = [
        'type' => '盤點單據',
        'no' => $ic['doc_no'] ?? ($ic['count_no'] ?? ($ic['id'] ?? '')),
        'target' => $ic['warehouse_name'] ?? '',
        'amount' => 0,
        'status' => $ic['status'] ?? '盤點中',
        'time' => $ic['created_at'] ?? '',
        'source' => '',
        'summary' => $ic['note'] ?? '',
        'link' => 'inventory-count',
      ];
    }
    foreach (($inventoryTransfers ?? []) as $it) {
      $erpDocs[] = [
        'type' => '調撥單據',
        'no' => $it['doc_no'] ?? ($it['transfer_no'] ?? ($it['id'] ?? '')),
        'target' => trim(($it['from_warehouse'] ?? '') . ' → ' . ($it['to_warehouse'] ?? ''), ' →'),
        'amount' => 0,
        'status' => $it['status'] ?? '調撥中',
        'time' => $it['created_at'] ?? '',
        'source' => '',
        'summary' => $it['note'] ?? '',
        'link' => 'inventory-transfer',
      ];
    }
    foreach (($inventoryAdjustments ?? []) as $ia) {
      $erpDocs[] = [
        'type' => ($ia['type'] ?? '') === 'loss' ? '盤虧單據' : '盤盈單據',
        'no' => $ia['doc_no'] ?? ($ia['id'] ?? ''),
        'target' => $ia['product_title'] ?? ($ia['product_id'] ?? ''),
        'amount' => 0,
        'status' => $ia['status'] ?? '已調整',
        'time' => $ia['created_at'] ?? '',
        'source' => '',
        'summary' => $ia['reason'] ?? ($ia['note'] ?? ''),
        'link' => 'inventory-adjustment',
      ];
    }
    usort($erpDocs, function($a, $b) { return strcmp((string)($b['time'] ?? ''), (string)($a['time'] ?? '')); });
    $opsDocTypes = array_values(array_unique(array_filter(array_map(function($d) { return trim((string)($d['type'] ?? '')); }, $erpDocs))));
    sort($opsDocTypes, SORT_NATURAL);
    $docTypeFilter = trim((string)($_GET['doc_type'] ?? ''));
    $docStatusFilter = trim((string)($_GET['doc_status'] ?? ''));
    $docQ = trim((string)($_GET['doc_q'] ?? ''));
    $docDateFrom = trim((string)($_GET['doc_date_from'] ?? ''));
    $docDateTo = trim((string)($_GET['doc_date_to'] ?? ''));
    $erpDocsFiltered = array_values(array_filter($erpDocs, function($d) use ($docTypeFilter, $docStatusFilter, $docQ, $docDateFrom, $docDateTo) {
      $time = substr((string)($d['time'] ?? ''), 0, 10);
      if ($docTypeFilter !== '' && (string)($d['type'] ?? '') !== $docTypeFilter) return false;
      if ($docStatusFilter !== '' && mb_strpos((string)($d['status'] ?? ''), $docStatusFilter) === false) return false;
      if ($docDateFrom !== '' && $time !== '' && $time < $docDateFrom) return false;
      if ($docDateTo !== '' && $time !== '' && $time > $docDateTo) return false;
      if ($docQ !== '') {
        $hay = implode(' ', [$d['type'] ?? '', $d['no'] ?? '', $d['target'] ?? '', $d['status'] ?? '', $d['source'] ?? '', $d['summary'] ?? '']);
        if (mb_stripos($hay, $docQ) === false) return false;
      }
      return true;
    }));
    $erpDocFilteredAmount = array_reduce($erpDocsFiltered, function($sum, $d) { return $sum + (float)($d['amount'] ?? 0); }, 0);
    $opsDocLimit = 10;
    $opsDocPage = max(1, (int)($_GET['doc_page'] ?? 1));
    $opsDocTotal = count($erpDocsFiltered);
    $opsDocPages = max(1, (int)ceil($opsDocTotal / $opsDocLimit));
    $opsDocPage = min($opsDocPage, $opsDocPages);
    $opsDocRows = array_slice($erpDocsFiltered, ($opsDocPage - 1) * $opsDocLimit, $opsDocLimit);
    $opsDocPageUrl = function($page) use ($docTypeFilter, $docStatusFilter, $docQ, $docDateFrom, $docDateTo) {
      return 'operations.php?' . http_build_query(['doc_page' => $page, 'doc_type' => $docTypeFilter, 'doc_status' => $docStatusFilter, 'doc_q' => $docQ, 'doc_date_from' => $docDateFrom, 'doc_date_to' => $docDateTo]) . '#document-center';
    };
    $erpTodayCount = count(array_filter($erpDocs, fn($d) => strpos((string)($d['time'] ?? ''), $erpToday) === 0));
    $erpMonthCount = count(array_filter($erpDocs, fn($d) => strpos((string)($d['time'] ?? ''), $erpMonth) === 0));
    $erpPendingCount = count(array_filter($erpDocs, fn($d) => preg_match('/待|退回|處理|盤點中|調撥中|借出中|維修中|遺失/u', (string)($d['status'] ?? ''))));
  ?>
  <section class="ops-card ops-tab" id="draft-center">
    <h2>單據流程中心 / 預審工作台</h2>
    <p class="muted">比照管家婆常用流程：先新增草稿，送審後由管理者核准，再轉正式單據；已轉正式的流程可作廢或建立沖帳單。</p>
    <div class="erp-action-grid">
      <a class="erp-action-card" href="#stock-in"><b>進貨入庫單</b><span>掃條碼、多筆明細、入庫增加庫存</span></a>
      <a class="erp-action-card" href="#customer-shipping"><b>銷售出庫單</b><span>客戶出貨、物流、金額與庫存扣除</span></a>
      <a class="erp-action-card" href="#quotations"><b>估價單</b><span>接總部估價單，後續可轉正式出貨</span></a>
      <a class="erp-action-card" href="#repair-documents"><b>維修單據</b><span>維修登記、處理狀態、客戶資料</span></a>
      <a class="erp-action-card" href="#inventory-count"><b>盤點單據</b><span>條碼盤點、差異核對、盤點紀錄</span></a>
      <a class="erp-action-card" href="#inventory-transfer"><b>調撥單據</b><span>倉庫 / 貨架 / 層位之間移動</span></a>
    </div>
    <form method="post" class="product-form document-workflow-form">
      <input type="hidden" name="action" value="save_document_workflow">
      <label>單據類型
        <select name="workflow_type" required>
          <option value="進貨入庫單">進貨入庫單</option>
          <option value="銷售出庫單">銷售出庫單</option>
          <option value="收款單">收款單</option>
          <option value="付款單">付款單</option>
          <option value="請款單">請款單</option>
          <option value="費用單">費用單</option>
          <option value="調撥單據">調撥單據</option>
          <option value="盤點單據">盤點單據</option>
        </select>
      </label>
      <label>單據日期<input type="date" name="workflow_date" value="<?=h(date('Y-m-d'))?>"></label>
      <label>對象 / 客戶 / 廠商<input name="workflow_target" required placeholder="例如 原價屋、客戶姓名、供應商"></label>
      <label>金額<input type="number" step="0.01" min="0" name="workflow_amount" value="0"></label>
      <label class="wide">摘要<input name="workflow_summary" placeholder="這張單據的用途、來源或主要說明"></label>
      <label class="wide">明細內容<textarea name="workflow_lines" rows="4" placeholder="可輸入品項、數量、備註。正式進貨/出貨明細仍建議到對應功能建立。"></textarea></label>
      <div class="form-actions wide">
        <button class="secondary" name="save_draft" value="1">儲存草稿</button>
        <button class="primary" name="submit_for_review" value="1">儲存並送審</button>
      </div>
    </form>
    <?php
      $workflowRows = array_reverse($documentWorkflows ?? []);
      $workflowLimit = 10;
      $workflowPage = max(1, (int)($_GET['workflow_page'] ?? 1));
      $workflowTotal = count($workflowRows);
      $workflowPages = max(1, (int)ceil($workflowTotal / $workflowLimit));
      $workflowPage = min($workflowPage, $workflowPages);
      $workflowShown = array_slice($workflowRows, ($workflowPage - 1) * $workflowLimit, $workflowLimit);
      $workflowPageUrl = function($page) { return 'operations.php?' . http_build_query(['workflow_page' => $page]) . '#draft-center'; };
    ?>
    <h3>流程單列表</h3>
    <div class="table-wrap document-workflow-table"><table><thead><tr><th>流程單號</th><th>類型</th><th>對象 / 摘要</th><th>金額</th><th>狀態</th><th>正式單號</th><th>更新時間</th><th>流程操作</th></tr></thead><tbody>
      <?php foreach($workflowShown as $wf): $wfStatus=(string)($wf['status'] ?? '草稿'); ?>
        <tr>
          <td><?=h($wf['workflow_no'] ?? '')?></td>
          <td><?=h(ops_document_type_label($wf['document_type'] ?? ''))?></td>
          <td><b><?=h($wf['target'] ?? '')?></b><?php if(trim((string)($wf['summary'] ?? '')) !== ''): ?><br><span class="muted"><?=h($wf['summary'])?></span><?php endif; ?></td>
          <td><?=money($wf['amount'] ?? 0)?></td>
          <td><?=h($wfStatus)?></td>
          <td><?=h($wf['formal_document_no'] ?? ($wf['offset_document_no'] ?? ''))?></td>
          <td><?=h(substr((string)($wf['updated_at'] ?? ($wf['created_at'] ?? '')), 0, 19))?></td>
          <td>
            <div class="workflow-actions">
              <?php if($wfStatus === '草稿'): ?><form method="post" class="inline-form"><input type="hidden" name="action" value="submit_document_workflow"><input type="hidden" name="workflow_id" value="<?=h($wf['id'] ?? '')?>"><button class="secondary small">送審</button></form><?php endif; ?>
              <?php if($wfStatus === '待審核'): ?><form method="post" class="inline-form"><input type="hidden" name="action" value="approve_document_workflow"><input type="hidden" name="workflow_id" value="<?=h($wf['id'] ?? '')?>"><button class="primary small">核准</button></form><?php endif; ?>
              <?php if(in_array($wfStatus, ['已核准','待審核'], true)): ?><form method="post" class="inline-form" onsubmit="return confirm('確定轉成正式單據？');"><input type="hidden" name="action" value="convert_document_workflow"><input type="hidden" name="workflow_id" value="<?=h($wf['id'] ?? '')?>"><button class="secondary small">轉正式</button></form><?php endif; ?>
              <?php if(!in_array($wfStatus, ['已作廢','已沖帳'], true)): ?><form method="post" class="inline-form" onsubmit="return confirm('確定作廢這張流程單？');"><input type="hidden" name="action" value="void_document_workflow"><input type="hidden" name="workflow_id" value="<?=h($wf['id'] ?? '')?>"><input type="hidden" name="workflow_note" value="管理者作廢"><button class="danger small">作廢</button></form><?php endif; ?>
              <?php if($wfStatus === '已轉正式'): ?><form method="post" class="inline-form" onsubmit="return confirm('確定建立沖帳單？系統會產生反向金額紀錄。');"><input type="hidden" name="action" value="offset_document_workflow"><input type="hidden" name="workflow_id" value="<?=h($wf['id'] ?? '')?>"><input type="hidden" name="workflow_note" value="管理者沖帳"><button class="danger small">沖帳</button></form><?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$documentWorkflows): ?><tr><td colspan="8" class="muted">目前尚無流程單。</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($workflowPages > 1): ?><div class="pager"><span>第 <?=h($workflowPage)?> / <?=h($workflowPages)?> 頁，每頁 10 筆</span><?php if($workflowPage > 1): ?><a class="secondary small" href="<?=h($workflowPageUrl($workflowPage - 1))?>">上一頁</a><?php endif; ?><?php if($workflowPage < $workflowPages): ?><a class="secondary small" href="<?=h($workflowPageUrl($workflowPage + 1))?>">下一頁</a><?php endif; ?></div><?php endif; ?>
  </section>

  <section class="ops-card ops-tab" id="document-center">
    <h2>單據中心 / 正式紀錄總表</h2>
    <p class="muted">正式單據總查詢：進貨、銷售、估價單轉出貨、維修、銷貨退回、盤點、調撥、收款都從這裡回查；資料會直接讀取各功能已建立的正式單據。</p>
    <form method="get" action="operations.php#document-center" class="product-form document-filter-form">
      <label>單據類型
        <select name="doc_type">
          <option value="">全部類型</option>
          <?php foreach($opsDocTypes as $type): ?>
            <option value="<?=h($type)?>" <?=($docTypeFilter===$type?'selected':'')?>><?=h(ops_document_type_label($type))?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>狀態關鍵字<input name="doc_status" value="<?=h($docStatusFilter)?>" placeholder="待處理 / 已匯入 / 已付款"></label>
      <label>起日<input type="date" name="doc_date_from" value="<?=h($docDateFrom)?>"></label>
      <label>迄日<input type="date" name="doc_date_to" value="<?=h($docDateTo)?>"></label>
      <label class="wide">單號 / 對象 / 摘要搜尋<input name="doc_q" value="<?=h($docQ)?>" placeholder="輸入單號、客戶、廠商、摘要、來源檔名"></label>
      <div class="form-actions">
        <button class="primary">查詢單據</button>
        <a class="button-like" href="operations.php#document-center">清除條件</a>
        <button type="button" class="secondary" id="printDocumentCenter">列印總表</button>
      </div>
    </form>
    <div class="metric-grid">
      <div class="metric"><span>全部正式單據</span><strong><?=h(count($erpDocs))?> 筆</strong><small>目前篩選 <?=h($opsDocTotal)?> 筆</small></div>
      <div class="metric"><span>篩選金額合計</span><strong><?=money($erpDocFilteredAmount)?></strong><small>依目前條件計算</small></div>
      <div class="metric"><span>今日 / 本月</span><strong><?=h($erpTodayCount)?> / <?=h($erpMonthCount)?> 筆</strong><small>全部資料統計</small></div>
      <div class="metric"><span>待處理 / 追蹤</span><strong><?=h($erpPendingCount)?> 筆</strong><small>含待、退回、處理、盤點、調撥等狀態</small></div>
    </div>
    <div class="erp-action-grid compact">
      <a class="erp-action-card" href="#stock-search"><b>庫存狀況表</b><span>看現有、預約、已售與可用庫存</span></a>
      <a class="erp-action-card" href="#finance-reconcile"><b>往來應收應付</b><span>客戶、廠商、收付款核對</span></a>
      <a class="erp-action-card" href="#finance-analytics"><b>銷售統計 / 利潤</b><span>營業額、毛利、虧損品項排行</span></a>
      <a class="erp-action-card" href="#document-center"><b>單據中心</b><span>所有正式單據集中查詢</span></a>
    </div>
    <div class="table-wrap document-center-table"><table><thead><tr><th>類型</th><th>單號</th><th>對象 / 摘要</th><th>金額</th><th>狀態</th><th>單據日期</th><th>來源</th><th>操作</th></tr></thead><tbody>
      <?php foreach($opsDocRows as $doc): ?>
        <tr>
          <td><?=h(ops_document_type_label($doc['type'] ?? ''))?></td>
          <td><?=h($doc['no'] ?? '')?></td>
          <td><b><?=h($doc['target'] ?? '')?></b><?php if(trim((string)($doc['summary'] ?? '')) !== ''): ?><br><span class="muted"><?=h($doc['summary'])?></span><?php endif; ?></td>
          <td><?=money($doc['amount'] ?? 0)?></td>
          <td><?=h($doc['status'] ?? '')?></td>
          <td><?=h(substr((string)($doc['time'] ?? ''), 0, 19))?></td>
          <td><?=h($doc['source'] ?? '')?></td>
          <td><a class="secondary small" href="#<?=h($doc['link'] ?? 'document-center')?>">查看來源</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$erpDocs): ?><tr><td colspan="8" class="muted">目前尚無正式單據；請先從草稿中心或左側單據功能建立。</td></tr><?php endif; ?>
      <?php if($erpDocs && !$opsDocRows): ?><tr><td colspan="8" class="muted">目前查詢條件沒有符合的單據。</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($opsDocPages > 1): ?>
      <div class="pager"><span>第 <?=h($opsDocPage)?> / <?=h($opsDocPages)?> 頁，每頁 10 筆</span><?php if($opsDocPage > 1): ?><a class="secondary small" href="<?=h($opsDocPageUrl($opsDocPage - 1))?>">上一頁</a><?php endif; ?><?php if($opsDocPage < $opsDocPages): ?><a class="secondary small" href="<?=h($opsDocPageUrl($opsDocPage + 1))?>">下一頁</a><?php endif; ?></div>
    <?php endif; ?>
  </section>


    <section class="ops-card ops-tab colored-section stock-in-section" id="stock-in">
    <h2>進貨單據</h2>
    <p class="muted">掃描產品條碼或輸入關鍵字搜尋。進貨成本與 LINGZANZAN 後台相同：人民幣 × 5 + 集運分攤 + 倉別人事。拼多多商品價已含中國運費，只把台灣快遞 ÷ 關稅登記件數；豪鴻免稅、整批運費依計費重量分攤；東莞倉免關稅，只加每件人事 30。</p>
    <details class="payment-offset-panel">
      <summary><b>進貨成本預設規則</b>（與 LINGZANZAN 倉別人事對齊，管理者可調整）</summary>
      <form method="post" class="product-form">
        <input type="hidden" name="action" value="save_purchase_cost_rules">
        <label>人民幣固定倍率<input name="rmb_fixed_rate" type="number" min="0.0001" step="0.0001" value="<?=h($purchaseCostSettings['rmb_fixed_rate'] ?? 5)?>"></label>
        <label>東莞中國倉每件人事<input name="china_warehouse_fee" type="number" min="0" step="0.01" value="<?=h($purchaseCostSettings['china_warehouse_fee'] ?? 30)?>"></label>
        <label>寶輝台灣倉每件人事<input name="taiwan_warehouse_fee" type="number" min="0" step="0.01" value="<?=h($purchaseCostSettings['taiwan_warehouse_fee'] ?? 20)?>"></label>
        <label>其他來源分攤方式<select name="default_allocation_method"><option value="quantity" <?=($purchaseCostSettings['default_allocation_method'] ?? '')==='quantity'?'selected':''?>>依數量</option><option value="amount" <?=($purchaseCostSettings['default_allocation_method'] ?? '')==='amount'?'selected':''?>>依商品金額</option></select></label>
        <button class="secondary">儲存成本規則</button>
      </form>
    </details>
    <form method="post" class="product-form" id="stockInForm">
      <input type="hidden" name="action" value="stock_in">
      <label>進貨單號<input name="stock_doc_no" value="JH-<?=h(date('Ymd'))?>-儲存後自動流水" placeholder="空白由系統自動產生"></label>
      <label>單據日期<input type="date" name="stock_doc_date" value="<?=h(date('Y-m-d'))?>"></label>
      <label>廠商
        <select name="stock_supplier_id">
          <option value="">不指定廠商</option>
          <?php foreach($suppliers as $sp): ?>
            <option value="<?=h($sp['id'] ?? '')?>" <?=($sp['id'] ?? '')===$defaultStockSupplierId?'selected':''?>><?=h($sp['name'] ?? '')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>臨時廠商名稱<input name="stock_supplier_name" placeholder="沒有建檔時可先輸入"></label>
      <label>經手人<input name="stock_handler" value="<?=h(current_operator())?>"></label>
      <label>部門 / 成本中心<input name="stock_department" value="電腦部門"></label>
      <label>付款狀態<select name="stock_payment_status"><option>未付款</option><option>已付款</option><option>部分付款</option><option>月結</option></select></label>
      <label>發票 / 憑證號碼<input name="stock_invoice_no" placeholder="發票號碼、收據號碼"></label>
      <label>供應來源<select name="stock_source" id="stockSource"><option selected>拼多多</option><option>豪鴻</option><option>其他</option></select></label>
      <label>幣別<select name="stock_currency" id="stockCurrency"><option value="CNY" selected>人民幣 CNY</option><option value="TWD">台幣 TWD</option></select></label>
      <label>匯率 / 固定倍率<input name="stock_exchange_rate" id="stockExchangeRate" type="number" min="0.0001" step="0.0001" value="<?=h($purchaseCostSettings['rmb_fixed_rate'] ?? 5)?>"></label>
      <label id="stockAllocationMethodWrap">其他來源分攤方式<select name="stock_allocation_method" id="stockAllocationMethod"><option value="quantity" <?=($purchaseCostSettings['default_allocation_method'] ?? '')==='quantity'?'selected':''?>>依數量</option><option value="amount" <?=($purchaseCostSettings['default_allocation_method'] ?? '')==='amount'?'selected':''?>>依商品金額</option></select></label>
      <label>關稅登記件數<input name="stock_customs_package_count" id="stockCustomsPackageCount" type="number" min="0" step="1" value="" placeholder="空白＝本單數量"><small class="muted" id="stockCustomsCountHint">拼多多成本分母。空白則用本單數量。</small></label>
      <label class="wide">產品條碼 / 關鍵字搜尋
        <input id="stockProductSearch" autocomplete="off" placeholder="掃條碼，或輸入產品編號、條碼、名稱、顏色、尺寸">
      </label>
      <div id="stockProductResults" class="wide product-search-results"></div>
      <div class="wide table-wrap">
        <table class="compact-table">
          <thead><tr><th>產品</th><th>條碼 / 規格</th><th>數量</th><th>來源單價</th><th class="stock-weight-col">計費重量 kg</th><th>台幣基礎</th><th>分攤費用</th><th>最新單位成本</th><th>倉別</th><th>倉架名稱</th><th>倉架位置</th><th>備註</th><th>操作</th></tr></thead>
          <tbody id="stockInRows">
            <tr class="empty-stock-row"><td colspan="13" class="muted">請先掃描條碼或搜尋產品加入明細。</td></tr>
          </tbody>
        </table>
      </div>
      <label data-stock-other-fee>折扣<input type="number" min="0" step="0.01" name="stock_discount" id="stockDocumentDiscount" value="0"></label>
      <label data-stock-other-fee>其他稅額<input type="number" min="0" step="0.01" name="stock_tax" id="stockDocumentTax" value="0"></label>
      <label>關稅核對（不計入成本）<input type="number" min="0" step="0.01" name="stock_customs_fee" id="stockCustomsFee" value="0"><small class="muted" id="stockCustomsHint">拼多多關稅只作核對；豪鴻免稅固定 0。</small></label>
      <label>中國段運費<input type="number" min="0" step="0.01" name="stock_china_freight" id="stockChinaFreight" value="0"><small class="muted" id="stockChinaFreightHint">拼多多商品價已含中國運費，不計入成本。</small></label>
      <label>台灣快遞實收<input type="number" min="0" step="0.01" name="stock_taiwan_shipping" id="stockTaiwanShipping" value="0"><small class="muted" id="stockTaiwanHint">拼多多：這筆 ÷ 關稅登記件數＝每件額外成本。</small></label>
      <label>整批運費<input type="number" min="0" step="0.01" name="stock_shipping_fee" id="stockDocumentShippingFee" value="0"><small class="muted" id="stockShippingHint">豪鴻：依各列計費重量分攤。</small></label>
      <label data-stock-other-fee>其他費用<input type="number" min="0" step="0.01" name="stock_other_fee" id="stockDocumentOtherFee" value="0"></label>
      <div class="wide document-total-bar">
        <span>來源換算合計 <b id="stockLineTotal">$0</b></span>
        <span>完整到岸成本 <b id="stockDocumentTotal">$0</b></span>
      </div>
      <div class="wide ops-alert" id="stockDocumentFormula">拼多多：人民幣 × 5 + 台灣快遞 ÷ 關稅登記件數 + 倉別人事。關稅只作核對，商品價已含中國運費。東莞倉免關稅，只加每件 30。</div>
      <label class="wide">整單備註<textarea name="stock_doc_note" rows="2" placeholder="付款條件、採購備註、憑證說明"></textarea></label>
      <button class="primary" onclick="return confirm('請確認畫面中的完整到岸成本。送出後將增加庫存並更新產品最新成本。');">確認成本並正式入庫</button>
    </form>
    <h3>最近進貨紀錄</h3>
    <div class="table-wrap"><table><thead><tr><th>時間</th><th>產品</th><th>條碼</th><th>顏色 / 尺碼</th><th>數量</th><th>成本</th><th>廠商</th><th>倉位</th><th>操作人</th><th>備註</th></tr></thead><tbody>
      <?php
        $opsStockInLimit = 10;
        $opsStockInRowsAll = array_reverse($stockMovements);
        $opsStockInPage = max(1, (int)($_GET['stock_in_page'] ?? 1));
        $opsStockInTotal = count($opsStockInRowsAll);
        $opsStockInPages = max(1, (int)ceil($opsStockInTotal / $opsStockInLimit));
        $opsStockInPage = min($opsStockInPage, $opsStockInPages);
        $opsStockInRows = array_slice($opsStockInRowsAll, ($opsStockInPage - 1) * $opsStockInLimit, $opsStockInLimit);
        $opsStockInPageUrl = function($page) { return 'operations.php?' . http_build_query(['stock_in_page' => $page]) . '#stock-in'; };
      ?>
      <?php foreach($opsStockInRows as $mv): ?>
        <tr>
          <td><?=h(substr($mv['created_at'] ?? '', 0, 19))?></td>
          <td><b><?=h($mv['product_id'] ?? '')?></b><br><?=h($mv['product_title'] ?? '')?></td>
          <td><?=h($mv['barcode'] ?? '')?></td>
          <td><?=h(trim(($mv['color'] ?? '') . ' / ' . ($mv['size'] ?? '') . ' / ' . ($mv['spec'] ?? ''), ' /'))?></td>
          <td><?=h($mv['qty'] ?? 0)?></td>
          <td><?=money($mv['unit_cost'] ?? 0)?></td>
          <td><?=h($mv['supplier_name'] ?? '')?></td>
          <td><?=h(trim(($mv['warehouse_name'] ?? '') . ' / ' . ($mv['shelf_code'] ?? '') . ' / ' . ($mv['warehouse_location'] ?? ''), ' /'))?></td>
          <td><?=h($mv['operator'] ?? '')?></td>
          <td><?=h($mv['note'] ?? '')?></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$stockMovements): ?><tr><td colspan="10" class="muted">目前尚無進貨紀錄。</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($opsStockInPages > 1): ?>
      <div class="pager"><span>第 <?=h($opsStockInPage)?> / <?=h($opsStockInPages)?> 頁，每頁 10 筆</span><?php if($opsStockInPage > 1): ?><a class="secondary small" href="<?=h($opsStockInPageUrl($opsStockInPage - 1))?>">上一頁</a><?php endif; ?><?php if($opsStockInPage < $opsStockInPages): ?><a class="secondary small" href="<?=h($opsStockInPageUrl($opsStockInPage + 1))?>">下一頁</a><?php endif; ?></div>
    <?php endif; ?>
    <h3>最近成本變更紀錄</h3>
    <div class="table-wrap"><table><thead><tr><th>時間</th><th>進貨單</th><th>產品</th><th>舊成本</th><th>最新成本</th><th>匯率</th><th>最新條碼</th><th>操作者</th><th>公式</th></tr></thead><tbody>
      <?php foreach(array_slice(array_reverse($purchaseCostAudits), 0, 10) as $auditRow): ?>
        <tr><td><?=h(substr($auditRow['created_at'] ?? '', 0, 19))?></td><td><?=h($auditRow['document_no'] ?? '')?></td><td><?=h($auditRow['product_id'] ?? '')?></td><td><?=money($auditRow['old_cost'] ?? 0)?></td><td><b><?=money($auditRow['new_cost'] ?? 0)?></b></td><td><?=h(($auditRow['currency'] ?? 'TWD') . ' × ' . ($auditRow['exchange_rate'] ?? 1))?></td><td><?=h($auditRow['latest_label_barcode'] ?? '')?></td><td><?=h($auditRow['operator'] ?? '')?></td><td><?=h($auditRow['formula'] ?? '')?></td></tr>
      <?php endforeach; ?>
      <?php if(!$purchaseCostAudits): ?><tr><td colspan="9" class="muted">尚無成本變更紀錄；完成第一張正式進貨單後會自動建立。</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

  <section class="ops-card ops-tab colored-section supplier-section" id="suppliers">
    <h2>廠商建檔</h2>
    <p class="muted">供應商、廠商、批發來源先建在這裡；進貨單據可直接選廠商。產品建檔的「預設供應來源」手打後會自動出現在下面分類。</p>
    <div class="supplier-category-bar" id="supplierCategoryChips">
      <?php
        $supplierCategoryGroups = [];
        foreach ($suppliers as $sp) {
            $cat = supplier_search_category($sp);
            $supplierCategoryGroups[$cat] = ($supplierCategoryGroups[$cat] ?? 0) + 1;
        }
        ksort($supplierCategoryGroups, SORT_NATURAL);
      ?>
      <button type="button" class="stock-chip category is-active" data-supplier-category="">全部分類（<?=h(count($suppliers))?>）</button>
      <?php foreach ($supplierCategoryGroups as $catName => $catCount): ?>
        <button type="button" class="stock-chip category" data-supplier-category="<?=h($catName)?>"><?=h($catName)?>（<?=h($catCount)?>）</button>
      <?php endforeach; ?>
    </div>
    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_supplier">
      <label>廠商名稱<input name="supplier_name" required></label>
      <label>聯絡人<input name="supplier_contact"></label>
      <label>電話<input name="supplier_phone"></label>
      <label>統編<input name="supplier_tax_id"></label>
      <label class="wide">地址<input name="supplier_address"></label>
      <button class="primary">儲存廠商</button>
    </form>
    <?php
      $opsSupplierLimit = 10;
      $opsSupplierPage = max(1, (int)($_GET['supplier_page'] ?? 1));
      $opsSupplierTotal = count($suppliers);
      $opsSupplierPages = max(1, (int)ceil($opsSupplierTotal / $opsSupplierLimit));
      $opsSupplierPage = min($opsSupplierPage, $opsSupplierPages);
      $supplierShownPage = array_slice($suppliers, ($opsSupplierPage - 1) * $opsSupplierLimit, $opsSupplierLimit);
      $opsSupplierPageUrl = function($page) { return 'operations.php?' . http_build_query(['supplier_page' => $page]) . '#suppliers'; };
    ?>
    <form id="supplierBulkDeleteForm" method="post" onsubmit="return confirm('確定刪除勾選的廠商？此操作無法復原。');"><input type="hidden" name="action" value="delete_suppliers"></form>
    <div class="bulk-bar"><label class="check"><input type="checkbox" data-select-all="supplier_ids[]"> 全選本頁</label><button class="danger-button" type="submit" form="supplierBulkDeleteForm">刪除勾選廠商</button></div>
    <div class="table-wrap"><table id="supplierDirectoryTable"><thead><tr><th>選</th><th>廠商</th><th>分類</th><th>聯絡人</th><th>電話</th><th>統編</th><th>地址</th><th>操作</th></tr></thead><tbody>
      <?php foreach($supplierShownPage as $sp): ?>
        <tr data-supplier-id="<?=h($sp['id'] ?? '')?>" data-supplier-name="<?=h($sp['name'] ?? '')?>" data-supplier-category="<?=h(supplier_search_category($sp))?>" data-created-at="<?=h($sp['created_at'] ?? '')?>">
          <td><input type="checkbox" name="supplier_ids[]" value="<?=h($sp['id'] ?? '')?>" form="supplierBulkDeleteForm"></td>
          <td><?=h($sp['name'] ?? '')?></td>
          <td><?=h(supplier_search_category($sp))?></td>
          <td><?=h($sp['contact'] ?? '')?></td>
          <td><?=h($sp['phone'] ?? '')?></td>
          <td><?=h($sp['tax_id'] ?? '')?></td>
          <td><?=h($sp['address'] ?? '')?></td>
          <td><form method="post" class="inline-form" onsubmit="return confirm('確定刪除此廠商？');"><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="supplier_id" value="<?=h($sp['id'] ?? '')?>"><button class="danger small">刪除</button></form></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$suppliers): ?><tr data-supplier-empty="1"><td colspan="8" class="muted">目前尚未建立廠商。</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($opsSupplierPages > 1): ?>
      <div class="pager"><span>第 <?=h($opsSupplierPage)?> / <?=h($opsSupplierPages)?> 頁，每頁 10 筆；顯示 <?=h(count($supplierShownPage))?> / <?=h($opsSupplierTotal)?> 筆</span><?php if($opsSupplierPage > 1): ?><a class="secondary small" href="<?=h($opsSupplierPageUrl($opsSupplierPage - 1))?>">上一頁</a><?php endif; ?><?php if($opsSupplierPage < $opsSupplierPages): ?><a class="secondary small" href="<?=h($opsSupplierPageUrl($opsSupplierPage + 1))?>">下一頁</a><?php endif; ?></div>
    <?php endif; ?>
  </section>

  <section class="ops-card ops-tab" id="member-create">
    <h2>客戶建檔</h2>
    <p class="muted">這裡只做新增客戶/會員主檔；搜尋、黑名單、交易統計請到「會員搜尋」。</p>
    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_member">
      <label>姓名<input name="name" required></label>
      <label>Facebook<input name="facebook"></label>
      <label>電話<input name="phone"></label>
      <label class="wide">地址<input name="address"></label>
      <label>黑名單狀態<select name="blacklist_status"><option>正常</option><option>觀察</option><option>黑名單</option></select></label>
      <label>風險等級<select name="risk_level"><option>一般</option><option>中風險</option><option>高風險</option></select></label>
      <label class="wide">風險原因<textarea name="blacklist_reason" rows="2"></textarea></label>
      <label class="wide">備註<textarea name="note" rows="2"></textarea></label>
      <button class="primary">儲存會員</button>
    </form>
  </section>

  <section class="ops-card ops-tab" id="color-modules">
    <div class="section-head">
      <div>
        <h2>顏色尺碼模組</h2>
        <p class="muted">產品建檔的「顏色尺碼類別」會帶這裡的模組面板。可在產品建檔頁直接新增，也可以在這裡改名稱、顏色與尺寸。</p>
      </div>
    </div>
    <form method="post" class="mini-form color-module-create">
      <input type="hidden" name="action" value="save_color_module">
      <label>模組名稱<input name="color_module_name" placeholder="例如：女裝常用 / 鞋類尺寸"></label>
      <label class="wide">顏色清單<textarea name="color_module_colors" rows="3" placeholder="黑、白、灰、米、粉；可用逗號或換行分隔"></textarea></label>
      <label class="wide">尺寸清單<textarea name="color_module_sizes" rows="3" placeholder="F、S、M、L、XL；可用逗號或換行分隔"></textarea></label>
      <button class="primary">新增顏色尺碼模組</button>
    </form>
    <div class="barcode-code-panel">
      <h3>服裝部共用碼表（產品建檔直接使用）</h3>
      <p class="muted">產品建檔的顏色／尺寸從此表帶出。公司條碼也用同一套顏色碼與尺寸碼，例如 JA316P600913 代表成本 600、顏色碼 91、尺寸碼 3。</p>
      <div class="barcode-code-grid">
        <div><h4>顏色碼</h4><?php foreach($barcodeColorCodes as $code => $name): ?><span class="code-chip"><b><?=h($code)?></b> <?=h($name)?></span><?php endforeach; ?></div>
        <div><h4>尺寸碼</h4><?php foreach($barcodeSizeCodes as $code => $name): ?><span class="code-chip"><b><?=h($code)?></b> <?=h($name)?></span><?php endforeach; ?></div>
      </div>
    </div>

    <form id="colorModulesBulkDeleteForm" method="post" onsubmit="return confirm('確定刪除勾選的顏色尺碼模組？產品既有顏色與尺寸文字不會刪除。');">
      <input type="hidden" name="action" value="delete_color_modules">
    </form>
    <div class="bulk-bar">
      <label class="check"><input type="checkbox" data-select-all="color_module_ids[]"> 全選顏色尺碼模組</label>
      <button class="danger-button" type="submit" form="colorModulesBulkDeleteForm">刪除勾選模組</button>
    </div>
    <div class="color-module-grid">
      <?php foreach($colorModules as $module): $mid = h($module['id'] ?? ''); ?>
      <div class="color-module-card">
        <div class="color-module-card-head">
          <label class="check"><input type="checkbox" name="color_module_ids[]" value="<?=$mid?>" form="colorModulesBulkDeleteForm"> 選取</label>
          <form method="post" class="inline-form" onsubmit="return confirm('確定刪除此顏色尺碼模組？產品既有資料不會刪除。');">
            <input type="hidden" name="action" value="delete_color_module">
            <input type="hidden" name="color_module_id" value="<?=$mid?>">
            <button class="danger small">刪除</button>
          </form>
        </div>
        <form method="post" class="color-module-edit-form">
          <input type="hidden" name="action" value="save_color_module">
          <input type="hidden" name="color_module_id" value="<?=$mid?>">
          <label>模組名稱<input name="color_module_name" value="<?=h($module['name'] ?? '')?>"></label>
          <label>顏色<textarea name="color_module_colors" rows="3"><?=h(implode("\n", $module['colors'] ?? []))?></textarea></label>
          <label>尺寸<textarea name="color_module_sizes" rows="3"><?=h(implode("\n", $module['sizes'] ?? []))?></textarea></label>
          <button class="secondary">儲存此模組</button>
        </form>
      </div>
      <?php endforeach; ?>
      <?php if(!$colorModules): ?><p class="muted">目前尚未建立顏色尺碼模組。</p><?php endif; ?>
    </div>
  </section>

  <section class="ops-card ops-tab" id="stock-search">
    <style>
      #stock-search select,
      #stock-search option,
      #stock-search input,
      #stock-search button {
        background: #fff !important;
        color: #0f172a !important;
      }
      #stock-search .stock-filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 12px;
        align-items: end;
        margin: 12px 0 16px;
      }
      #stock-search .stock-filter-grid label {
        min-width: 0;
      }
      #stock-search .stock-filter-grid input,
      #stock-search .stock-filter-grid select {
        width: 100%;
        box-sizing: border-box;
      }
      #stock-search .stock-tools {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        margin: 10px 0 14px;
      }
      #stock-search .stock-card-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 12px;
      }
      #stock-search .stock-card {
        border: 1px solid #d7e0ec;
        border-radius: 8px;
        background: #fff;
        padding: 14px;
      }
      #stock-search .stock-card-main {
        display: grid;
        grid-template-columns: 128px minmax(240px, 1fr) minmax(280px, 360px);
        gap: 16px;
        align-items: start;
      }
      #stock-search .stock-main-img,
      #stock-search .stock-no-img {
        width: 112px;
        height: 112px;
        border: 1px solid #d7e0ec;
        border-radius: 8px;
        object-fit: cover;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f8fafc;
        color: #64748b;
        font-weight: 700;
      }
      #stock-search .stock-thumb-row {
        display: flex;
        gap: 6px;
        margin-top: 8px;
        flex-wrap: wrap;
      }
      #stock-search .stock-thumb-row img {
        width: 42px;
        height: 42px;
        object-fit: cover;
        border: 1px solid #d7e0ec;
        border-radius: 6px;
      }
      #stock-search .stock-title {
        font-size: 18px;
        margin-bottom: 6px;
      }
      #stock-search .stock-chip-row {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        margin: 8px 0;
      }
      #stock-search .stock-chip {
        display: inline-flex;
        border: 1px solid #cbd5e1;
        background: #f8fafc;
        border-radius: 999px;
        padding: 3px 9px;
        font-size: 13px;
      }
      #stock-search .stock-numbers {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 8px;
      }
      #stock-search .stock-numbers strong {
        color: #047857;
      }
      #stock-search .stock-detail-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;margin:9px 0}
      #stock-search .stock-detail-grid span{border:1px solid #dbe3ee;border-radius:7px;padding:7px;background:#f8fafc;min-width:0}
      #stock-search .stock-detail-grid small{display:block;color:#64748b;margin-bottom:2px}
      #stock-search .stock-detail-grid b{display:block;overflow-wrap:anywhere;color:#0f172a}
      #stock-search .stock-detail-grid .current-stock{background:#ecfdf5;border-color:#86efac}.stock-detail-grid .current-stock b{color:#047857;font-size:18px}
      #stock-search .barcode-print-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}
      #stock-search .barcode-print-actions a{font-weight:900;border-radius:6px;padding:7px 10px;text-decoration:none}
      #stock-search .barcode-print-actions .label-40{background:#0f766e;color:#fff;border:1px solid #0f766e}
      #stock-search .barcode-print-actions .label-30{background:#1d4ed8;color:#fff;border:1px solid #1d4ed8}
      #stock-search .quick-image-form {
        display: grid;
        gap: 8px;
        border-left: 3px solid #0f766e;
        padding-left: 12px;
      }
      #stock-search .quick-image-form input[type=file] {
        border: 1px solid #d7e0ec;
        border-radius: 6px;
        padding: 7px;
      }
      #stock-search .stock-pager {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        align-items: center;
        margin: 14px 0 4px;
      }
      @media (max-width: 980px) {
        #stock-search .stock-card-main {
          grid-template-columns: 1fr;
        }
        #stock-search .stock-detail-grid{grid-template-columns:1fr 1fr}
      }
    </style>
    <div class="section-head">
      <div>
        <h2>庫存管理</h2>
        <p class="muted">這裡方便查庫存、補圖、改成本、列印條碼。要改名稱、分類、顏色尺寸或倉位，請按「編輯產品」。</p>
      </div>
    </div>

    <form method="get" action="operations.php#stock-search" class="stock-filter-grid">
      <label>產品關鍵字
        <input name="stock_q" value="<?=h($stockQ)?>" placeholder="編號 / 條碼 / 名稱 / 顏色 / 尺寸">
      </label>
      <label>部門
        <select name="stock_department">
          <option value="">全部部門</option>
          <?php foreach($departmentOptions as $dept): ?>
            <option value="<?=h($dept)?>" <?=($stockDepartment===$dept?'selected':'')?>><?=h($dept)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>群組
        <select name="stock_category_group" id="stockCategoryGroupSelect" data-current="<?=h($stockCategoryGroup)?>">
          <option value="">全部群組</option>
          <?php foreach($categoryGroupOptions as $group): ?>
            <option value="<?=h($group)?>" <?=($stockCategoryGroup===$group?'selected':'')?>><?=h($group)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>類別
        <select name="stock_category_type" id="stockCategoryTypeSelect" data-current="<?=h($stockCategoryType)?>">
          <option value="">全部類別</option>
        </select>
      </label>
      <label>廠牌
        <select name="stock_category_brand" id="stockCategoryBrandSelect" data-current="<?=h($stockCategoryBrand)?>">
          <option value="">全部廠牌</option>
        </select>
      </label>
      <label>細分類
        <select name="stock_category_spec" id="stockCategorySpecSelect" data-current="<?=h($stockCategorySpec)?>">
          <option value="">全部細分類</option>
        </select>
      </label>
      <label>可用庫存至少
        <input type="number" min="0" name="stock_min_available" value="<?=h($stockMinAvailable ?: '')?>" placeholder="例：1 / 2 / 10">
      </label>
      <label>倉別
        <select name="stock_warehouse">
          <option value="">全部倉別</option>
          <?php foreach($warehouseOptions as $w): ?>
            <option value="<?=h($w)?>" <?=($stockWarehouse===$w?'selected':'')?>><?=h($w)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>倉架名稱
        <select name="stock_shelf">
          <option value="">全部倉架名稱</option>
          <?php foreach($shelfOptions as $shelf): ?>
            <option value="<?=h($shelf)?>" <?=($stockShelf===$shelf?'selected':'')?>><?=h($shelf)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>倉架位置
        <select name="stock_layer">
          <option value="">全部倉架位置</option>
          <?php foreach($layerOptions as $layer): ?>
            <option value="<?=h($layer)?>" <?=($stockLayer===$layer?'selected':'')?>><?=h($layer)?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>商品狀態
        <select name="stock_condition">
          <option value="">全部</option>
          <option value="全新品" <?=($stockCondition==='全新品'?'selected':'')?>>全新品</option>
          <option value="二手品" <?=($stockCondition==='二手品'?'selected':'')?>>二手品</option>
        </select>
      </label>
      <label>圖片狀態
        <select name="stock_image_filter">
          <option value="">全部圖片狀態</option>
          <option value="no_main" <?=($stockImageFilter==='no_main'?'selected':'')?>>無主圖</option>
          <option value="no_any" <?=($stockImageFilter==='no_any'?'selected':'')?>>完全無圖</option>
        </select>
      </label>
      <label>建檔起日
        <input type="date" name="stock_date_from" value="<?=h($stockDateFrom)?>">
      </label>
      <label>建檔迄日
        <input type="date" name="stock_date_to" value="<?=h($stockDateTo)?>">
      </label>
      <div class="stock-tools">
        <button class="secondary">搜尋庫存</button>
        <a class="button-like" href="operations.php?stock_min_available=1#stock-search">只看有庫存</a>
        <a class="button-like" href="operations.php?stock_department=電腦部門&stock_min_available=1#stock-search">電腦部門有庫存</a>
        <a class="button-like" href="operations.php?stock_image_filter=no_main#stock-search">只看無主圖</a>
        <a class="button-like" href="operations.php#stock-search">清除</a>
      </div>
    </form>

    <form method="post" id="stockProductsForm" onsubmit="return confirm('確定刪除勾選產品？已有排程的產品會保護不刪除。');">
      <input type="hidden" name="action" value="bulk_delete_products">
    </form>
    <form method="post" id="stockCostForm">
      <input type="hidden" name="action" value="update_stock_costs">
    </form>
    <div class="bulk-actions">
      <label class="check"><input type="checkbox" data-select-all="product_ids[]"> 全選本頁</label>
      <button class="danger" type="submit" form="stockProductsForm">刪除勾選產品</button>
      <button class="secondary" type="submit" form="stockCostForm">儲存本頁成本</button>
      <span class="muted">第 <?=h($stockPage)?> / <?=h($stockPages)?> 頁，每頁 10 筆；目前符合 <?=h($stockFilteredTotal)?> 筆。</span>
    </div>

    <div class="stock-card-grid">
      <?php foreach($stockShown as $p): $imgs=product_images($p); $categoryPath=trim(implode(' / ', array_filter([$p['category_group']??'', $p['category_type']??'', $p['category_brand']??'', $p['category_spec']??''], function($v){ return trim((string)$v) !== ''; }))); $specText=trim(implode(' / ', array_filter([$p['color']??'', $p['size']??'', $p['spec']??''], function($v){ return trim((string)$v) !== ''; }))); $stockNameText=trim((string)($p['warehouse_name']??'')); $stockPositionText=stock_position_label($p['shelf_code']??'', $p['warehouse_location']??''); if($stockPositionText===$stockNameText) $stockPositionText=''; $deptText=trim((string)($p['department'] ?? '')); $reservedTotal=stock_reserved_total($p); $cloudReserved=cloud_auction_reserved($p); ?>
      <div class="stock-card">
        <div class="stock-card-main">
          <div class="stock-card-images">
            <?php if(!empty($imgs[0])): ?><img class="stock-main-img zoomable" src="<?=h($imgs[0])?>" title="產品主圖"><?php else: ?><span class="stock-no-img">無主圖</span><?php endif; ?>
            <div class="photo-capture-actions">
              <label class="photo-btn camera small">拍照上傳<input type="file" accept="image/*" capture="environment" data-quick-photo-product="<?=h($p['id']??'')?>" data-quick-photo-role="main"></label>
              <button type="button" class="photo-btn paste small" data-photo-paste-self>貼上</button>
              <button type="button" class="secondary small product-capture-button" data-product-id="<?=h($p['id']??'')?>" onclick="captureProductMainImage(this)">✂ 擷取主圖</button>
            </div>
            <?php if(count($imgs)>1): ?><div class="stock-thumb-row"><?php foreach(array_slice($imgs,1,6) as $img): ?><img class="zoomable" src="<?=h($img)?>" title="其他產品照片"><?php endforeach; ?></div><?php endif; ?>
          </div>
          <div class="stock-card-info">
            <label class="stock-card-check"><input type="checkbox" name="product_ids[]" value="<?=h($p['id']??'')?>" form="stockProductsForm"> 選取</label>
            <div class="stock-title"><b><?=h($p['id']??'')?></b> <?=h($p['title']??'')?></div>
            <div class="stock-detail-grid">
              <span><small>產品條碼</small><b><?=h(($p['barcode']??'') ?: '-')?></b></span>
              <span><small>顏色</small><b><?=h(($p['color']??'') ?: '未設定')?></b></span>
              <span><small>尺寸</small><b><?=h(($p['size']??'') ?: '未設定')?></b></span>
              <span class="current-stock"><small>目前庫存</small><b><?=h((int)($p['stock_total']??0))?></b></span>
            </div>
            <div class="stock-chip-row">
              <?php if($deptText): ?><span class="stock-chip">部門：<?=h($deptText)?></span><?php endif; ?>
              <span class="stock-chip"><?=h(($p['product_condition'] ?? '') ?: '未設定')?></span>
              <?php if($categoryPath): ?><span class="stock-chip"><?=h($categoryPath)?></span><?php endif; ?>
              <?php if($specText): ?><span class="stock-chip"><?=h($specText)?></span><?php endif; ?>
            </div>
            <div>倉別：<?=h($stockNameText ?: '-')?></div>
            <?php if($stockPositionText): ?><div class="muted">倉架名稱 / 位置：<?=h($stockPositionText)?></div><?php endif; ?>
            <div class="stock-numbers">
              <span>目前庫存 <?=h((int)($p['stock_total']??0))?></span>
              <span>預約 <?=h($reservedTotal)?><?php if($cloudReserved): ?>（競標 <?=h($cloudReserved)?>）<?php endif; ?></span>
              <span>已售 <?=h((int)($p['stock_sold']??0))?></span>
              <strong>可用 <?=h(stock_available($p))?></strong>
            </div>
            <?php if(!empty($p['cloud_auction_locked'])): ?><div class="ops-alert"><b>競標中鎖倉</b>：已有雲端競標場次，禁止重複排程上架。</div><?php endif; ?>
            <div class="barcode-print-actions">
              <a class="button-like" href="operations.php?edit_product=<?=urlencode($p['id']??'')?>#products">編輯產品</a>
              <a class="label-40" target="_blank" rel="noopener" href="operations.php?print_cost_barcode=<?=urlencode($p['id']??'')?>&label_size=40x30">列印 40×30 mm</a>
              <a class="label-30" target="_blank" rel="noopener" href="operations.php?print_cost_barcode=<?=urlencode($p['id']??'')?>&label_size=30x30">列印 30×30 mm</a>
            </div>
            <label class="stock-cost-inline">成本
              <input name="product_costs[<?=h($p['id']??'')?>]" form="stockCostForm" type="number" step="0.01" value="<?=h($p['cost']??0)?>">
            </label>
          </div>
          <form method="post" enctype="multipart/form-data" class="quick-image-form">
            <input type="hidden" name="action" value="quick_product_images">
            <input type="hidden" name="product_id" value="<?=h($p['id']??'')?>">
            <?php render_photo_capture_fields('quick_main_image', 'quick_extra_images', [
              'class' => '',
              'title' => '快速補圖 / 拍照',
              'hint' => '可直接拍照、從相簿選圖，或 Ctrl+V 貼上圖片，再按儲存圖片。',
              'main_label' => '主圖（1 張，會取代）',
              'extra_label' => '其他細圖（可多張追加）',
            ]); ?>
            <button class="primary">儲存圖片</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if(!$stockShown): ?><div class="muted stock-empty">目前沒有符合條件的產品。</div><?php endif; ?>
    </div>

    <div class="stock-pager">
      <?php if($stockPage > 1): ?><a class="button-like" href="<?=h($stockPageUrl($stockPage - 1))?>">上一頁</a><?php endif; ?>
      <span class="muted">第 <?=h($stockPage)?> / <?=h($stockPages)?> 頁</span>
      <?php if($stockPage < $stockPages): ?><a class="button-like" href="<?=h($stockPageUrl($stockPage + 1))?>">下一頁</a><?php endif; ?>
    </div>
  </section>

  <section class="ops-card ops-tab" id="cloud-inventory-sync">
    <div class="section-head">
      <div>
        <h2>雲端庫存與競標鎖倉</h2>
        <p class="muted">以「新上架」的總庫存、上架後數量、已售出及各倉架數量為庫存基準，再用「各年度」的日期、結標時間、序號、得標人與出貨日期核對售出及鎖倉狀態。</p>
      </div>
      <div class="form-actions">
        <a class="button-like" target="_blank" rel="noopener" href="https://docs.google.com/spreadsheets/d/1xGtKBpa3glooWMSb4QgIWDdHz0Nr5m54/edit?gid=657180316#gid=657180316">開啟新上架表</a>
        <a class="button-like" target="_blank" rel="noopener" href="https://docs.google.com/spreadsheets/d/1CgPkICplrYbT9ZBf4j0kdzsB9BGjz-8R-7KdOZqDgPw/edit?gid=2056963421#gid=2056963421">開啟競標紀錄</a>
      </div>
    </div>
    <div class="overview-metrics">
      <div class="metric"><span>來源品項</span><strong><?=h((int)($cloudInventorySummary['source_rows'] ?? 0))?></strong><small>新上架明細</small></div>
      <div class="metric"><span>精準配對</span><strong><?=h((int)($cloudInventorySummary['matched_products'] ?? 0))?></strong><small>條碼與顏色吻合</small></div>
      <div class="metric"><span>確認已售</span><strong><?=h((int)($cloudInventorySummary['confirmed_sold_units'] ?? 0))?></strong><small>以出貨日期核對</small></div>
      <div class="metric"><span>競標鎖倉</span><strong><?=h((int)($cloudInventorySummary['active_locked_units'] ?? 0))?></strong><small><?=h((int)($cloudInventorySummary['active_locked_products'] ?? 0))?> 項產品</small></div>
    </div>
    <div class="ops-alert">最後比對：<?=h(str_replace('T', ' ', (string)($cloudInventorySummary['generated_at'] ?? '尚未比對')))?>。有出貨日期才列為已售；有得標人但尚無出貨日期會保持待出貨鎖倉；尚未結標的場次列為競標中鎖倉。不確定品項保持原資料。</div>

    <h3>目前競標中鎖倉</h3>
    <div class="table-wrap"><table><thead><tr><th>產品</th><th>顏色</th><th>總庫存</th><th>確認已售</th><th>鎖倉</th><th>可用</th><th>鎖倉狀態 / 場次</th></tr></thead><tbody>
      <?php foreach($cloudInventoryLockedRows as $row): ?>
      <tr>
        <td><b><?=h($row['product_id'] ?? '')?></b><br><span class="muted"><?=h($row['barcode'] ?? '')?></span></td>
        <td><?=h(($row['color'] ?? '') ?: '-')?></td>
        <td><?=h((int)($row['base_stock'] ?? 0))?></td>
        <td><?=h((int)($row['final_sold'] ?? 0))?></td>
        <td><b><?=h((int)($row['cloud_reserved'] ?? 0))?></b></td>
        <td><?=h((int)($row['available'] ?? 0))?></td>
        <td><?php $lockedEvidence = array_merge($row['pending_shipments'] ?? [], $row['active_auctions'] ?? []); foreach(array_slice($lockedEvidence, 0, 8) as $auction): ?><div><b><?=!empty($auction['winner'])?'得標待出貨':'競標中'?></b>　<?=h($auction['auction_at'] ?? '')?>　<?=h($auction['serial'] ?? '')?></div><?php endforeach; ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(!$cloudInventoryLockedRows): ?><tr><td colspan="7" class="muted">目前沒有競標中鎖倉。</td></tr><?php endif; ?>
    </tbody></table></div>

    <h3>需要人工確認</h3>
    <div class="table-wrap"><table><thead><tr><th>來源列</th><th>條碼</th><th>顏色</th><th>來源庫存</th><th>原因</th></tr></thead><tbody>
      <?php foreach(array_slice($cloudInventoryIssueRows, 0, 30) as $row): ?>
      <tr>
        <td><?=h((int)($row['source_row'] ?? 0))?></td>
        <td><b><?=h($row['barcode'] ?? '')?></b></td>
        <td><?=h(($row['color'] ?? '') ?: '-')?></td>
        <td><?=h((int)($row['base_stock'] ?? 0))?></td>
        <td><?php if(empty($row['balance_valid'])): ?>新上架表的庫存加總不一致<?php elseif(($row['product_match_reason'] ?? '') === 'product_not_found'): ?>後台尚無此產品<?php else: ?>同條碼或顏色無法唯一配對<?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(!$cloudInventoryIssueRows): ?><tr><td colspan="5" class="muted">所有來源資料都已完成配對。</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if(count($cloudInventoryIssueRows) > 30): ?><p class="muted">目前先顯示 30 筆，尚有 <?=h(count($cloudInventoryIssueRows) - 30)?> 筆等待產品建檔或顏色確認。</p><?php endif; ?>
  </section>

  <section class="ops-card ops-tab" id="warehouses">
    <div class="section-head">
      <div>
        <h2>貨倉管理</h2>
        <p class="muted">倉架名稱只建立編號；每個倉架的位置固定為上層或下層。產品建檔與入庫共用：倉別 → 倉架名稱 → 倉架位置。</p>
      </div>
    </div>
    <div class="overview-metrics">
      <?php foreach(array_slice($warehouseOptions, 0, 6) as $w): ?>
      <div class="metric"><span><?=h($w)?></span><strong><?=h(warehouse_count($products, $w))?></strong><small>可用庫存</small></div>
      <?php endforeach; ?>
      <?php if(!$warehouseOptions): ?><div class="metric"><span>尚未建立貨倉</span><strong>0</strong><small>請先新增倉庫</small></div><?php endif; ?>
    </div>
    <div class="warehouse-workflow-note"><b>欄位定義：倉別預設電腦倉，倉架名稱＝編號，倉架位置＝上層／下層。</b> 沒選倉架名稱代表還沒放上去；選了倉架後才指定上層或下層。</div>
    <form method="post" class="mini-form warehouse-layer-add-form">
      <input type="hidden" name="action" value="save_warehouse_item">
      <input type="hidden" name="warehouse_item_type" value="combo">
      <label>部門<select name="warehouse_item_department" id="warehouseAddDepartmentSelect"><?php foreach($departmentOptions as $dept): ?><option value="<?=h($dept)?>" <?=$dept==='電腦部門'?'selected':''?>><?=h($dept)?></option><?php endforeach; ?></select></label><label>替哪個貨架新增倉位<select name="warehouse_shelf_pair" id="warehouseShelfPairSelect">
        <option value="">請選倉別 / 倉架名稱</option>
        <?php foreach($warehouseTree = warehouse_tree($warehouses) as $warehouseNode): foreach(($warehouseNode['shelves'] ?? []) as $shelfNode): ?>
        <option value="<?=h(($warehouseNode['department'] ?? '') . '||' . ($warehouseNode['name'] ?? '') . '||' . ($shelfNode['name'] ?? ''))?>" data-department="<?=h($warehouseNode['department'] ?? '')?>"><?=h(($warehouseNode['department'] ?? '') . ' / ' . ($warehouseNode['name'] ?? '') . ' / ' . ($shelfNode['name'] ?? ''))?></option>
        <?php endforeach; endforeach; ?>
      </select></label>
      <label>倉架位置<select name="warehouse_item_layer"><option value="">請選擇</option><option value="上層">上層</option><option value="下層">下層</option></select></label>
      <button class="primary">新增倉架位置</button>
    </form>
    <details class="warehouse-advanced-add"><summary>進階新增：新增倉庫 / 貨架編號</summary>
      <form method="post" class="mini-form">
        <input type="hidden" name="action" value="save_warehouse_item">
        <label>部門<select name="warehouse_item_department" id="warehouseAdvancedDepartmentSelect"><?php foreach($departmentOptions as $dept): ?><option value="<?=h($dept)?>" <?=$dept==='電腦部門'?'selected':''?>><?=h($dept)?></option><?php endforeach; ?></select></label><label>新增類型<select name="warehouse_item_type"><option value="warehouse">倉別</option><option value="shelf">倉架名稱</option></select></label>
        <label>名稱<input name="warehouse_item_name" placeholder="例如：電腦倉 / A01"></label>
        <label>所屬倉別<input name="warehouse_item_warehouse" list="warehouse-list" placeholder="新增倉架時選擇，例如：電腦倉"></label>
        <button class="secondary">新增進階設定</button>
      </form>
    </details>
    <div class="warehouse-tree-tools">
      <label>部門<select id="warehouseTreeDepartmentSelect">
        <option value="">全部部門</option>
        <?php foreach($departmentOptions as $dept): ?><option value="<?=h($dept)?>" <?=$dept==='電腦部門'?'selected':''?>><?=h($dept)?></option><?php endforeach; ?>
      </select></label>
      <label>先選主倉位<select id="warehouseTreeSelect">
        <option value="">全部主倉位</option>
        <?php foreach($warehouseTree as $warehouseNode): if(($warehouseNode['department'] ?? '') !== '電腦部門') continue; ?><option value="<?=h($warehouseNode['name'] ?? '')?>"><?=h($warehouseNode['name'] ?? '')?></option><?php endforeach; ?>
      </select></label>
      <label>再搜尋貨架 / 倉位<input id="warehouseTreeSearch" placeholder="例如：A01、上層、下層"></label>
      <span class="muted">排序方式：部門 → 倉庫 → 貨架編號 → 倉位；預設顯示電腦部門</span>
    </div>
    <div class="warehouse-tree" id="warehouseTree">
      <?php foreach($warehouseTree as $warehouseNode): ?>
      <?php $warehouseSearchText = trim(($warehouseNode['department'] ?? '') . ' ' . ($warehouseNode['name'] ?? '') . ' ' . implode(' ', array_keys($warehouseNode['shelves'] ?? []))); ?>
      <div class="warehouse-tree-card" data-department="<?=h($warehouseNode['department'] ?? '')?>" data-warehouse="<?=h($warehouseNode['name'] ?? '')?>" data-warehouse-search="<?=h($warehouseSearchText)?>">
        <div class="warehouse-tree-head">
          <h3><?=h(($warehouseNode['department'] ?? '') . ' / ' . ($warehouseNode['name'] ?? ''))?></h3>
          <span class="status-pill">可用庫存 <?=h(warehouse_count($products, $warehouseNode['name'] ?? ''))?></span>
        </div>
        <?php if(!empty($warehouseNode['shelves'])): foreach($warehouseNode['shelves'] as $shelfNode): ?>
          <?php $rowSearchText = trim(($warehouseNode['name'] ?? '') . ' ' . ($shelfNode['name'] ?? '') . ' ' . implode(' ', $shelfNode['layers'] ?? [])); ?>
          <div class="warehouse-tree-shelf" data-warehouse-search="<?=h($rowSearchText)?>">
            <b><?=h($shelfNode['name'] ?? '')?></b>
            <div class="warehouse-layer-tags">
              <?php foreach(($shelfNode['layers'] ?? []) as $layerName): ?><span class="warehouse-layer-tag"><?=h($layerName)?></span><?php endforeach; ?>
              <?php if(empty($shelfNode['layers'])): ?>
                <span class="muted">尚未設定倉位</span>
                <form method="post" class="inline-layer-form">
                  <input type="hidden" name="action" value="save_warehouse_item">
                  <input type="hidden" name="warehouse_item_type" value="combo">
                  <input type="hidden" name="warehouse_item_department" value="<?=h($warehouseNode['department'] ?? '')?>">
                  <input type="hidden" name="warehouse_item_warehouse" value="<?=h($warehouseNode['name'] ?? '')?>">
                  <input type="hidden" name="warehouse_item_shelf" value="<?=h($shelfNode['name'] ?? '')?>">
                  <select name="warehouse_item_layer"><option value="上層">上層</option><option value="下層">下層</option></select>
                  <button class="secondary small">加倉位</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; else: ?>
          <div class="warehouse-empty">此主倉位尚未建立貨架與夾層組合。</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if(!$warehouseTree): ?><div class="warehouse-empty">目前尚未建立貨倉設定。</div><?php endif; ?>
    </div>
    <?php
      $opsWarehouseLimit = 10;
      $opsWarehousePage = max(1, (int)($_GET['warehouse_page'] ?? 1));
      $opsWarehouseTotal = count($warehouses);
      $opsWarehousePages = max(1, (int)ceil($opsWarehouseTotal / $opsWarehouseLimit));
      $opsWarehousePage = min($opsWarehousePage, $opsWarehousePages);
      $warehouseShownPage = array_slice($warehouses, ($opsWarehousePage - 1) * $opsWarehouseLimit, $opsWarehouseLimit);
      $opsWarehousePageUrl = function($page) { return 'operations.php?' . http_build_query(['warehouse_page' => $page]) . '#warehouses'; };
    ?>
    <details class="warehouse-raw-details"><summary>進階刪除區：原始設定明細，平常不用看</summary><p class="muted">主要請看上方「正式貨倉樹狀表」。這裡只用來刪除錯誤資料，所以會看到 shelf / combo 原始資料。</p><form id="warehouseBulkDeleteForm" method="post" onsubmit="return confirm('確定刪除勾選的貨倉設定？產品既有倉位文字不會刪除。');"><input type="hidden" name="action" value="delete_warehouse_items"></form><div class="bulk-bar"><label class="check"><input type="checkbox" data-select-all="warehouse_item_ids[]"> 全選貨倉設定</label><button class="danger-button" type="submit" form="warehouseBulkDeleteForm">刪除勾選貨倉設定</button></div><div class="table-wrap"><table><thead><tr><th>選</th><th>部門</th><th>類型</th><th>名稱</th><th>倉庫</th><th>貨架</th><th>層位</th><th>操作</th></tr></thead><tbody>
      <?php foreach($warehouseShownPage as $w): ?>
      <tr><td><input type="checkbox" name="warehouse_item_ids[]" value="<?=h($w['id']??'')?>" form="warehouseBulkDeleteForm"></td><td><?=h($w['department']??'')?></td><td><?=h($w['type']??'')?></td><td><?=h($w['name']??'')?></td><td><?=h($w['warehouse']??'')?></td><td><?=h($w['shelf']??'')?></td><td><?=h($w['layer']??'')?></td><td><form method="post" class="inline-form" onsubmit="return confirm('確定刪除這筆貨倉設定？產品既有倉位文字不會刪除。');"><input type="hidden" name="action" value="delete_warehouse_item"><input type="hidden" name="warehouse_item_id" value="<?=h($w['id']??'')?>"><button class="danger small">刪除</button></form></td></tr>
      <?php endforeach; ?>
      <?php if(!$warehouses): ?><tr><td colspan="8" class="muted">目前尚未建立貨倉設定。</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($opsWarehousePages > 1): ?>
      <div class="pager"><span>第 <?=h($opsWarehousePage)?> / <?=h($opsWarehousePages)?> 頁，每頁 10 筆；顯示 <?=h(count($warehouseShownPage))?> / <?=h($opsWarehouseTotal)?> 筆</span><?php if($opsWarehousePage > 1): ?><a class="secondary small" href="<?=h($opsWarehousePageUrl($opsWarehousePage - 1))?>">上一頁</a><?php endif; ?><?php if($opsWarehousePage < $opsWarehousePages): ?><a class="secondary small" href="<?=h($opsWarehousePageUrl($opsWarehousePage + 1))?>">下一頁</a><?php endif; ?></div>
    <?php endif; ?>
    </details>
  </section>
<section class="ops-card ops-tab" id="schedule">
    <h2>排程上架工作台</h2>
    <datalist id="schedule-product-options"><?php foreach($products as $p): ?><option value="<?=h(($p['id']??'').' / '.($p['barcode']??'').' / '.($p['title']??''))?>"></option><?php endforeach; ?></datalist>
    <form method="post" enctype="multipart/form-data" class="schedule-form">
      <input type="hidden" name="action" value="create_schedule">
      <input type="hidden" name="schedule_image" id="selectedScheduleImage">
      <label class="wide">產品關鍵字<input id="scheduleProductSearch" list="schedule-product-options" autocomplete="off" placeholder="輸入產品編號、條碼、名稱、類別、細分類、顏色或尺寸"><span class="schedule-search-hint" id="scheduleProductSearchHint">共 <?=h(count($products))?> 個商品；零庫存商品也會顯示。</span></label>
      <input type="hidden" id="scheduleProductKey" name="product_key">
      <div class="wide schedule-product-picker">
        <div class="picker-head">
          <strong>候選產品</strong>
          <span class="muted">先按「選擇此產品」，確認正確後再帶入排程。</span>
        </div>
        <div id="scheduleProductResults" class="product-result-list">請先輸入產品關鍵字。</div>
        <div class="product-confirm-bar">
          <div id="schedulePendingProductText" class="muted">尚未選擇產品。</div>
          <button type="button" class="primary" id="confirmScheduleProduct" disabled>確認帶入產品</button>
        </div>
      </div>
      <div class="wide schedule-product-preview" id="scheduleProductPreview">確認帶入產品後，這裡會顯示產品主圖、小圖、標題、規格、倉位與可用庫存。</div>
      <label>上架模式<select name="publish_mode"><option value="scheduled">排程上架</option><option value="instant">即時上架</option></select></label>
      <label>發文套組<select name="post_set_id">
        <?php foreach ($postReplySets as $postSet): ?>
          <option value="<?=h($postSet['id'] ?? '')?>" <?= !empty($postSet['is_default']) ? 'selected' : '' ?>><?=h(($postSet['name'] ?? '套組') . '（' . post_reply_scene_label((string)($postSet['scene'] ?? '')) . '）')?></option>
        <?php endforeach; ?>
      </select></label>
      <label class="check">顯示在買家今日看板<input type="checkbox" name="show_on_buyer_board" value="1" checked></label>
      <input type="hidden" name="quantity" value="1">
      <input type="hidden" name="publish_date" value="<?=h(date('Y-m-d'))?>">
      <input type="hidden" name="publish_time" value="20:00">
      <input type="hidden" name="close_date" value="<?=h(date('Y-m-d'))?>">
      <input type="hidden" name="close_time" value="23:59">
      <textarea name="days" hidden></textarea>
      <div class="wide operator-notice">員工機制：此頁新增排程、修改結算、修改物流會記錄目前登入帳號與時間，方便追蹤是哪位員工操作。</div>
      <div class="wide schedule-job-builder">
        <div class="section-head compact"><h3>工作排程（一筆一欄）</h3><span>一張卡就是一個上架工作；員工建立後會記錄操作帳號與時間。</span></div>
        <div id="scheduleJobRows" class="schedule-job-rows">
          <div class="schedule-job-row" data-schedule-job-row>
            <div class="schedule-job-index">1</div>
            <label class="schedule-publish-date">上架日期<input name="job_date[]" type="date" value="<?=h(date('Y-m-d'))?>"></label>
            <label class="schedule-publish-time">上架時間<input name="job_publish_time[]" type="time" value="20:00"></label>
            <label class="schedule-close-date">截標日期<input name="job_close_date[]" type="date" value="<?=h(date('Y-m-d'))?>"></label>
            <label class="schedule-close-time">截標時間<input name="job_close_time[]" type="time" value="23:59"></label>
            <label class="schedule-quantity">數量<input name="job_quantity[]" type="number" min="1" value="1"></label>
            <div class="schedule-job-actions">
              <button type="button" class="secondary small schedule-row-close" data-time="12:59">12:59</button>
              <button type="button" class="secondary small schedule-row-close" data-time="23:59">23:59</button>
              <button type="button" class="danger small remove-schedule-job">刪除</button>
            </div>
          </div>
        </div>
        <div class="schedule-job-toolbar">
          <button type="button" class="secondary" id="addScheduleJob">新增一筆工作排程</button>
          <span class="muted">不用再輸入多行日期；要排幾場，就新增幾列。</span>
        </div>
      </div>
      <label>產品序號 / 保固序號（可選填）<input name="product_serial" placeholder="例如 SN20260703001，打單保固用"></label>
      <label data-image-paste>本場補上傳圖片（可貼上）<input name="schedule_image_upload" type="file" accept="image/*"></label>
      <label>圖片備註<input name="schedule_image_note"></label>
      <button class="primary">建立排程並預約庫存</button>
    </form>

    <div class="section-head"><h2>排程上架工作</h2><span>上架序依「預定上架時間」由早到晚。Codex／人工發海賊團時照這個順序，系統不會自動發 Facebook。當日比對與執行稿在「臉書當日日報」。</span></div>
    <form method="get" class="inline-actions">
      <label>結標狀態<select name="close_status"><option value="">全部</option><option value="open" <?=($filterClose==='open'?'selected':'')?>>未結標</option><option value="closed" <?=($filterClose==='closed'?'selected':'')?>>已結標</option></select></label>
      <label>記單狀態<select name="order"><option value="">全部</option><?php foreach(['待記單','已記單','備貨中','等待出貨','已出貨','完成','退回處理','取消'] as $v): ?><option <?=($filterOrder===$v?'selected':'')?>><?=h($v)?></option><?php endforeach; ?></select></label>
      <button class="secondary">查詢排程</button>
    </form>
    <form id="scheduleBulkDeleteForm" method="post" onsubmit="return confirm('確定要刪除勾選的排程？系統會同步退回預約庫存。');">
      <input type="hidden" name="action" value="delete_schedules">
    </form>
    <div class="bulk-bar schedule-bulk-bar">
      <label class="check"><input type="checkbox" data-select-all="schedule_ids[]"> 全選排程</label>
      <button class="danger-button" type="submit" form="scheduleBulkDeleteForm">刪除勾選排程</button>
      <span class="muted">刪除排程會同步退回該商品的預約庫存。</span>
    </div>
    <div class="schedule-stats">
      <?php
        $today = date('Y-m-d'); $online=0; $pendingToday=0; $postedToday=0;
        foreach($schedules as $ss) {
          if (substr((string)($ss['publish_at']??''),0,10)===$today && ($ss['publish_status']??'未上架')==='未上架') $pendingToday++;
          if (substr((string)($ss['actual_publish_at']??''),0,10)===$today || (($ss['publish_status']??'')==='已上架' && substr((string)($ss['publish_at']??''),0,10)===$today)) $postedToday++;
          if (($ss['publish_status']??'')==='已上架' && strtotime($ss['close_at']??'') > time()) $online++;
        }
      ?>
      <div class="metric"><span>目前在線競標</span><strong><?=h($online)?></strong></div>
      <div class="metric"><span>今日預定未上架</span><strong><?=h($pendingToday)?></strong></div>
      <div class="metric"><span>今日已上架</span><strong><?=h($postedToday)?></strong></div>
    </div>
        <div class="schedule-card-list">
      <?php foreach($scheduleQueue as $queueIndex => $s): $p=product_by_id($products,$s['product_id']??''); $img=$s['schedule_image']??($p['image']??''); $closed=(strtotime($s['close_at']??'') && strtotime($s['close_at'])<=time()); $pubStatus=$s['publish_status']??$s['status']??'未上架'; $cardClass=$closed?'is-closed':($pubStatus==='已上架'?'is-posted':($pubStatus==='未上架成功'?'is-failed':'is-pending')); $spec=trim(($p['color']??'').' / '.($p['size']??'').' / '.($p['spec']??''),' /'); $pickedPostSet=post_reply_find_set($postReplySets,(string)($s['post_set_id']??'')); $pickedPostSetId=(string)($pickedPostSet['id']??''); $listingDraft=render_post_reply_listing($s,$p,$postReplySets,$pickedPostSetId); $qaDraft=render_post_reply_qa_pack($s,$p,$postReplySets,$pickedPostSetId); $postSetPayload=[]; foreach($postReplySets as $postSetRow){ $sid=(string)($postSetRow['id']??''); $postSetPayload[$sid]=['listing'=>render_post_reply_listing($s,$p,$postReplySets,$sid),'qa'=>render_post_reply_qa_pack($s,$p,$postReplySets,$sid)]; } ?>
      <article class="schedule-work-card <?=h($cardClass)?>">
        <div class="schedule-card-select"><input type="checkbox" name="schedule_ids[]" value="<?=h($s['id']??'')?>" form="scheduleBulkDeleteForm" aria-label="選取 <?=h($s['product_id']??'')?>"></div>
        <div class="schedule-card-media">
          <?php if($img): ?><img class="schedule-card-img zoomable" src="<?=h($img)?>" alt="<?=h($p['title']??($s['product_title']??''))?>"><?php else: ?><div class="schedule-card-noimg">無圖片</div><?php endif; ?>
          <span class="status-pill">本場 <?=h($s['quantity']??1)?> / 可用 <?=h(stock_available($p))?></span>
        </div>
        <div class="schedule-card-main">
          <div class="schedule-card-id"><span class="schedule-queue-no">上架序 <?=h(str_pad((string)($queueIndex + 1), 2, '0', STR_PAD_LEFT))?></span> <?=h($s['product_id']??'')?></div>
          <div class="schedule-card-title"><?=h($p['title']??($s['product_title']??''))?></div>
          <?php if($spec): ?><div class="schedule-card-spec"><?=h($spec)?></div><?php endif; ?>
          <div class="schedule-card-meta">
            <div class="schedule-meta-box"><span>預定上架</span><b><?=h($s['scheduled_publish_at']??($s['publish_at']??''))?></b></div>
            <div class="schedule-meta-box"><span>實際上架</span><b><?=h($s['actual_publish_at']??'-')?></b></div>
            <div class="schedule-meta-box"><span>截標提醒</span><b><?=h($s['close_remind_at']??($s['close_at']??''))?></b></div>
            <div class="schedule-meta-box"><span>目前競標金額</span><b><?=money($s['current_bid']??($s['winning_price']??0))?></b></div>
          </div>
          <div class="schedule-status-row">
            <span class="schedule-status-chip <?= $closed ? 'closed' : '' ?>"><?= $closed ? '已結標' : '未結標' ?></span>
            <span class="schedule-status-chip <?= $pubStatus==='已上架' ? 'posted' : ($pubStatus==='未上架成功' ? 'failed' : '') ?>"><?=h($pubStatus)?></span>
            <?php if(!empty($s['post_url'])): ?><a class="schedule-status-chip" href="<?=h(safe_http_url($s['post_url']))?>" target="_blank" rel="noopener">開貼文</a><?php else: ?><span class="schedule-status-chip closed">貼文待補</span><?php endif; ?>
          </div>
        </div>
        <div class="schedule-card-actions">
          <form method="post" class="mini-form">
            <input type="hidden" name="action" value="save_publish_status">
            <input type="hidden" name="schedule_id" value="<?=h($s['id'])?>">
            <label>上架狀態<select name="publish_status"><?php foreach(['未上架','已上架','未上架成功','補上架'] as $v): ?><option <?= ($pubStatus===$v?'selected':'') ?>><?=h($v)?></option><?php endforeach; ?></select></label>
            <label>發文套組<select name="post_set_id" class="schedule-post-set-pick" data-payload="<?=h(json_encode($postSetPayload, JSON_UNESCAPED_UNICODE))?>">
              <?php foreach($postReplySets as $postSetRow): $sid=(string)($postSetRow['id']??''); ?>
                <option value="<?=h($sid)?>" <?= $pickedPostSetId===$sid?'selected':'' ?>><?=h(($postSetRow['name']??'套組').(!empty($postSetRow['is_default'])?'（預設）':''))?></option>
              <?php endforeach; ?>
            </select></label>
            <label>實際上架<input name="actual_publish_at" type="datetime-local" value="<?=h(str_replace(' ', 'T', $s['actual_publish_at']??''))?>"></label>
            <label class="wide">貼文網址<input name="post_url" value="<?=h($s['post_url']??'')?>" placeholder="https://www.facebook.com/..."></label>
            <label>目前競標金額<input name="current_bid" type="number" min="0" step="1" value="<?=h($s['current_bid']??($s['winning_price']??0))?>"></label>
            <label class="check">買家看板<input type="checkbox" name="show_on_buyer_board" value="1" <?= (($s['show_on_buyer_board']??'1')!=='0'?'checked':'') ?>></label>
            <label class="wide">上架文案（依套組帶入，可複製貼到 Facebook）<textarea class="schedule-listing-draft" rows="8" readonly><?=h($listingDraft)?></textarea></label>
            <div class="wide form-button-row">
              <button type="button" class="secondary copy-facebook-listing">複製上架文案</button>
              <button type="button" class="secondary copy-schedule-qa">複製問答包</button>
              <a class="button-like" href="#post-scripts" data-jump-tab="post-scripts">改套組文案</a>
            </div>
            <label class="wide">問答包（可當第一則留言置頂）<textarea class="schedule-qa-draft" rows="8" readonly><?=h($qaDraft)?></textarea></label>
            <label class="wide">每小時提醒草稿<textarea rows="4" readonly><?=h(trim((string)($s['reminder_draft']??'')) ?: reminder_draft_text($s, $p))?></textarea></label>
            <div class="wide muted small">目前金額更新：<?=h($s['bid_updated_at']??'-')?>　上次提醒：<?=h($s['last_reminder_at']??'-')?>　下次提醒：<?=h($s['next_reminder_at']??reminder_next_time($s))?>　狀態：<?=h($s['reminder_status']??(schedule_needs_hourly_update($s)?'需要更新':'待確認發布'))?></div>
            <label class="wide">備註<input name="publish_note" value="<?=h($s['publish_note']??'')?>"></label>
            <div class="wide form-button-row">
              <button class="secondary">更新</button>
              <button class="secondary" name="generate_reminder" value="1">產生提醒草稿</button>
              <button class="secondary" name="mark_reminded" value="1">標記已提醒</button>
            </div>
          </form>
          <form method="post" class="inline-form" onsubmit="return confirm('確定刪除這一筆排程？系統會同步退回預約庫存。');">
            <input type="hidden" name="action" value="delete_schedules">
            <input type="hidden" name="schedule_ids[]" value="<?=h($s['id']??'')?>">
            <button class="danger small" type="submit">刪除這筆排程</button>
          </form>
        </div>
      </article>
      <?php endforeach; ?>
      <?php if(!$shown): ?><div class="stock-empty">目前沒有符合條件的排程。</div><?php endif; ?>
    </div>
  </section>

  <section class="ops-card ops-tab" id="facebook-daily">
    <div class="section-head">
      <div>
        <h2>臉書當日上架日報</h2>
        <p class="muted">看出今天要上架／已上架哪些產品，並比對缺貼文、待提醒、待得標通知。Codex 依這份日報執行發文、第一則問答、價格提醒與得標通知；系統不會自動發 Facebook。</p>
      </div>
      <div class="form-actions">
        <a class="button-like" href="operations.php?partial=facebook_daily&amp;date=<?=h($facebookDailyDate)?>" target="_blank" rel="noopener">JSON（給 Codex）</a>
        <a class="button-like" href="operations.php?partial=facebook_daily_text&amp;date=<?=h($facebookDailyDate)?>" target="_blank" rel="noopener">純文字執行稿</a>
      </div>
    </div>
    <form method="get" action="operations.php#facebook-daily" class="fb-daily-toolbar">
      <input type="hidden" name="tab" value="facebook-daily">
      <label>日報日期<input type="date" name="fb_day" value="<?=h($facebookDailyDate)?>"></label>
      <button class="secondary">查這天</button>
      <a class="button-like" href="operations.php?fb_day=<?=h(date('Y-m-d'))?>#facebook-daily">回到今天</a>
    </form>
    <div class="fb-daily-compare">
      <div class="metric"><span>當日場次</span><strong><?=h((int)$facebookDailyCompare['planned'])?></strong><small>預定或當日實際上架</small></div>
      <div class="metric"><span>已上架</span><strong class="ok"><?=h((int)$facebookDailyCompare['posted'])?></strong></div>
      <div class="metric"><span>待發文</span><strong class="<?= ((int)$facebookDailyCompare['missing_post']>0?'bad':'ok') ?>"><?=h((int)$facebookDailyCompare['missing_post'])?></strong></div>
      <div class="metric"><span>缺貼文網址</span><strong class="<?= ((int)$facebookDailyCompare['missing_url']>0?'warn':'ok') ?>"><?=h((int)$facebookDailyCompare['missing_url'])?></strong></div>
      <div class="metric"><span>待置頂問答</span><strong><?=h((int)$facebookDailyCompare['need_pin_qa'])?></strong></div>
      <div class="metric"><span>今日截標</span><strong><?=h((int)$facebookDailyCompare['closing'])?></strong></div>
      <div class="metric"><span>待價格提醒</span><strong class="<?= ((int)$facebookDailyCompare['need_remind']>0?'warn':'ok') ?>"><?=h((int)$facebookDailyCompare['need_remind'])?></strong></div>
      <div class="metric"><span>待得標通知</span><strong class="<?= ((int)$facebookDailyCompare['need_winner']>0?'bad':'ok') ?>"><?=h((int)$facebookDailyCompare['need_winner'])?></strong></div>
    </div>
    <label class="wide">給 Codex 的完整執行稿<button type="button" class="secondary small copy-fb-playbook">複製整份日報</button>
      <textarea id="facebookDailyPlaybook" class="fb-daily-playbook" readonly><?=h($facebookDailyCodexText)?></textarea>
    </label>

    <div class="table-wrap" style="margin-top:18px">
      <table>
        <thead><tr><th>序</th><th>圖</th><th>產品</th><th>預定上架</th><th>截標</th><th>狀態</th><th>貼文</th><th>金額</th><th>Codex 待辦</th></tr></thead>
        <tbody>
        <?php foreach ($facebookDailyRows as $row): $todos=[]; if(!empty($row['need_post'])) $todos[]='發文'; if(!empty($row['need_pin_qa'])) $todos[]='問答'; if(!empty($row['need_remind'])) $todos[]='提醒'; if(!empty($row['need_winner_record'])) $todos[]='補得標人'; elseif(!empty($row['need_winner'])) $todos[]='得標通知'; ?>
          <tr>
            <td><?=h(str_pad((string)$row['queue'], 2, '0', STR_PAD_LEFT))?></td>
            <td><?php if (!empty($row['image'])): ?><img class="thumb zoomable" src="<?=h($row['image'])?>" alt=""><?php endif; ?></td>
            <td><b><?=h($row['product_id'])?></b><br><?=h($row['title'])?><div class="muted"><?=h($row['spec'] ?: '-')?></div></td>
            <td><?=h($row['publish_at'])?></td>
            <td><?=h($row['close_at'])?></td>
            <td><?=h($row['publish_status'])?><br><span class="muted"><?=h($row['auction_status'])?></span></td>
            <td><?php if ($row['post_url'] !== ''): ?><a href="<?=h($row['post_url'])?>" target="_blank" rel="noopener">開貼文</a><?php else: ?><span class="muted">未回填</span><?php endif; ?></td>
            <td><?=money($row['current_bid'])?></td>
            <td><?= $todos ? h(implode('、', $todos)) : '已齊' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$facebookDailyRows): ?><tr><td colspan="9" class="muted">這天沒有上架或截標場次。</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php foreach ($facebookDailyRows as $row):
      $todo = !empty($row['need_post']) || !empty($row['need_pin_qa']) || !empty($row['need_remind']) || !empty($row['need_winner']);
      $cardClass = !empty($row['need_winner']) ? 'is-close' : ($todo ? 'is-todo' : 'is-done');
    ?>
    <article class="fb-daily-card <?=h($cardClass)?>">
      <div class="fb-daily-head">
        <?php if (!empty($row['image'])): ?><img src="<?=h($row['image'])?>" alt=""><?php else: ?><div class="fb-daily-noimg">無圖</div><?php endif; ?>
        <div>
          <b>上架序 <?=h(str_pad((string)$row['queue'], 2, '0', STR_PAD_LEFT))?>　<?=h($row['product_id'])?></b>
          <div><?=h($row['title'])?></div>
          <div class="muted"><?=h($row['spec'] ?: '-')?>　數量 <?=h($row['qty'])?>　套組 <?=h($row['post_set_name'] ?: '預設')?></div>
          <div class="fb-daily-chips">
            <span class="status-pill"><?=h($row['publish_status'])?></span>
            <span class="status-pill"><?=h($row['auction_status'])?></span>
            <?php if (!empty($row['need_post'])): ?><span class="status-pill">待發文</span><?php endif; ?>
            <?php if (!empty($row['need_pin_qa'])): ?><span class="status-pill">待問答</span><?php endif; ?>
            <?php if (!empty($row['need_remind'])): ?><span class="status-pill">待提醒</span><?php endif; ?>
            <?php if (!empty($row['need_winner'])): ?><span class="status-pill">待得標通知</span><?php endif; ?>
          </div>
        </div>
        <div class="muted" style="text-align:right">預定 <?=h($row['publish_at'])?><br>截標 <?=h($row['close_at'])?><br>金額 <?=money($row['current_bid'])?></div>
      </div>
      <form method="post" class="mini-form" style="margin-top:12px">
        <input type="hidden" name="action" value="save_facebook_daily_mark">
        <input type="hidden" name="schedule_id" value="<?=h($row['schedule_id'])?>">
        <label class="wide">Facebook 貼文網址<input name="post_url" value="<?=h($row['post_url'])?>" placeholder="https://www.facebook.com/..."></label>
        <label>目前競標金額<input name="current_bid" type="number" min="0" step="1" value="<?=h((int)$row['current_bid'])?>"></label>
        <div class="wide fb-daily-actions">
          <button class="secondary" name="mark" value="posted">標記已發文＋已置頂問答</button>
          <button class="secondary" name="mark" value="qa">只標記問答已置頂</button>
          <button class="secondary" name="mark" value="reminded">標記已價格提醒</button>
          <button class="secondary" name="mark" value="winner">標記已得標通知</button>
          <button class="secondary" name="mark" value="save">只存網址／金額</button>
          <a class="button-like" href="#schedule" data-jump-tab="schedule">回排程卡</a>
        </div>
      </form>
      <div class="fb-daily-copybox">
        <label>發文稿<button type="button" class="secondary small copy-fb-box">複製</button><textarea readonly><?=h($row['listing'])?></textarea></label>
        <label>第一則問答<button type="button" class="secondary small copy-fb-box">複製</button><textarea readonly><?=h($row['qa_pack'])?></textarea></label>
        <?php if (!empty($row['need_remind']) || !empty($row['in_publish_day'])): ?><label>價格提醒<button type="button" class="secondary small copy-fb-box">複製</button><textarea readonly><?=h($row['reminder'])?></textarea></label><?php endif; ?>
        <?php if (!empty($row['in_close_day'])): ?><label>得標通知<button type="button" class="secondary small copy-fb-box">複製</button><textarea readonly><?=h($row['winner_notice'])?></textarea></label><?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </section>

  <section class="ops-card ops-tab" id="post-scripts">
    <div class="section-head">
      <div>
        <h2>發文問答套組</h2>
        <p class="muted">客製化幾套「發文」與「常見問法／回答」。排程上架會依套組帶出文案，複製後貼到 Facebook／商城；系統不會自動發文。變數：<?=h(post_reply_placeholder_help())?></p>
      </div>
      <form method="post" onsubmit="return confirm('確定把全部套組還原成四套內建文案？你改過的內容會被蓋掉。');">
        <input type="hidden" name="action" value="restore_default_post_reply_sets">
        <button class="secondary" type="submit">還原四套內建文案</button>
      </form>
    </div>

    <form method="post" class="post-reply-editor mini-form" data-post-reply-editor="new">
      <input type="hidden" name="action" value="save_post_reply_set">
      <input type="hidden" name="set_id" value="">
      <h3 class="wide">新增一套</h3>
      <label>套組名稱<input name="set_name" required placeholder="例如：週末特賣發文"></label>
      <label>使用場景<select name="set_scene"><?php foreach (post_reply_scene_options() as $sceneKey => $sceneLabel): ?><option value="<?=h($sceneKey)?>"><?=h($sceneLabel)?></option><?php endforeach; ?></select></label>
      <label class="check">設成預設套組<input type="checkbox" name="is_default" value="1"></label>
      <label class="wide">備註<input name="set_note" placeholder="這套給誰用、什麼時候用"></label>
      <label class="wide">發文稿<textarea name="post_template" rows="8" placeholder="可貼上你平常發文的全文，變數用 {{title}} {{id}} {{spec}} {{close_at}} 等"></textarea></label>
      <div class="wide post-reply-qa-block">
        <div class="section-head compact"><h3>問法與回答</h3><span>買家訊息含問法或關鍵字時，可在下面「貼上買家訊息」對到回答。</span></div>
        <div class="post-reply-qa-rows" data-qa-rows>
          <div class="post-reply-qa-row">
            <label>問法<input name="qa_ask[]" placeholder="有現貨嗎？"></label>
            <label>關鍵字<input name="qa_aliases[]" placeholder="現貨,有貨,庫存"></label>
            <label class="wide">回答<textarea name="qa_answer[]" rows="2" placeholder="有，頭城門市現貨。"></textarea></label>
            <button type="button" class="danger small post-reply-qa-remove">刪這則</button>
          </div>
        </div>
        <button type="button" class="secondary small post-reply-qa-add">再加一則問答</button>
      </div>
      <div class="wide form-button-row"><button class="primary">儲存新套組</button></div>
    </form>

    <?php
      $firstPreviewProduct = (isset($products[0]) && is_array($products[0])) ? $products[0] : [];
      $postReplyPreviewProduct = [
          'id' => (string)(($firstPreviewProduct['id'] ?? '') ?: 'DEMO-001'),
          'title' => (string)(($firstPreviewProduct['title'] ?? '') ?: '範例商品：DDR4 3200 16G'),
          'barcode' => (string)(($firstPreviewProduct['barcode'] ?? '') ?: 'BH000001'),
          'color' => (string)(($firstPreviewProduct['color'] ?? '') ?: ''),
          'size' => (string)(($firstPreviewProduct['size'] ?? '') ?: ''),
          'spec' => (string)(($firstPreviewProduct['spec'] ?? '') ?: '16G / 3200'),
          'description' => (string)(($firstPreviewProduct['description'] ?? '') ?: '門市現貨，可自取。'),
          'sale_price' => $firstPreviewProduct['sale_price'] ?? ($firstPreviewProduct['selling_price'] ?? 990),
          'warehouse_name' => (string)(($firstPreviewProduct['warehouse_name'] ?? '') ?: '頭城門市'),
          'shelf_code' => (string)($firstPreviewProduct['shelf_code'] ?? ''),
          'warehouse_location' => (string)($firstPreviewProduct['warehouse_location'] ?? ''),
      ];
      $postReplyPreviewSchedule = [
          'product_id' => $postReplyPreviewProduct['id'],
          'product_title' => $postReplyPreviewProduct['title'],
          'product_barcode' => $postReplyPreviewProduct['barcode'],
          'product_spec' => $postReplyPreviewProduct['spec'],
          'product_description' => $postReplyPreviewProduct['description'],
          'close_at' => date('Y-m-d 23:59'),
          'publish_at' => date('Y-m-d 20:00'),
          'winning_price' => $postReplyPreviewProduct['sale_price'],
          'quantity' => 1,
      ];
      $factoryIds = [];
      foreach (default_post_reply_sets() as $factoryRow) $factoryIds[(string)($factoryRow['id'] ?? '')] = true;
    ?>

    <div class="post-reply-set-list">
      <?php foreach ($postReplySets as $postSet): $sid = (string)($postSet['id'] ?? ''); $isFactory = isset($factoryIds[$sid]); ?>
      <details class="post-reply-set-card" <?= !empty($postSet['is_default']) ? 'open' : '' ?>>
        <summary>
          <b><?=h($postSet['name'] ?? '未命名套組')?></b>
          <small><?=h(post_reply_scene_label((string)($postSet['scene'] ?? '')))?><?= !empty($postSet['is_default']) ? ' · 預設' : '' ?><?= $postSet['note'] ? ' · ' . h($postSet['note']) : '' ?></small>
        </summary>
        <div class="post-reply-set-body">
          <form method="post" class="post-reply-editor mini-form" data-post-reply-editor="<?=h($sid)?>">
            <input type="hidden" name="action" value="save_post_reply_set">
            <input type="hidden" name="set_id" value="<?=h($sid)?>">
            <label>套組名稱<input name="set_name" required value="<?=h($postSet['name'] ?? '')?>"></label>
            <label>使用場景<select name="set_scene">
              <?php foreach (post_reply_scene_options() as $sceneKey => $sceneLabel): ?>
                <option value="<?=h($sceneKey)?>" <?= (($postSet['scene'] ?? '') === $sceneKey) ? 'selected' : '' ?>><?=h($sceneLabel)?></option>
              <?php endforeach; ?>
            </select></label>
            <label class="check">設成預設套組<input type="checkbox" name="is_default" value="1" <?= !empty($postSet['is_default']) ? 'checked' : '' ?>></label>
            <label class="wide">備註<input name="set_note" value="<?=h($postSet['note'] ?? '')?>"></label>
            <label class="wide">發文稿<textarea name="post_template" rows="10" class="post-reply-template"><?=h($postSet['post_template'] ?? '')?></textarea></label>
            <div class="wide post-reply-preview-box">
              <div class="section-head compact"><h3>預覽（用目前庫存第一筆或範例商品帶入）</h3>
                <button type="button" class="secondary small copy-post-preview">複製預覽發文</button>
              </div>
              <textarea class="post-reply-preview" rows="8" readonly><?=h(render_post_reply_listing($postReplyPreviewSchedule, $postReplyPreviewProduct, $postReplySets, $sid))?></textarea>
            </div>
            <div class="wide post-reply-qa-block">
              <div class="section-head compact"><h3>問法與回答</h3><span>關鍵字可用逗號分隔，留言對到就帶這則回答。</span></div>
              <div class="post-reply-qa-rows" data-qa-rows>
                <?php $qaRows = (array)($postSet['qa'] ?? []); if (!$qaRows) $qaRows = [['ask'=>'','aliases'=>'','answer'=>'']]; foreach ($qaRows as $qaRow): ?>
                <div class="post-reply-qa-row">
                  <label>問法<input name="qa_ask[]" value="<?=h($qaRow['ask'] ?? '')?>"></label>
                  <label>關鍵字<input name="qa_aliases[]" value="<?=h($qaRow['aliases'] ?? '')?>"></label>
                  <label class="wide">回答<textarea name="qa_answer[]" rows="2"><?=h($qaRow['answer'] ?? '')?></textarea></label>
                  <button type="button" class="danger small post-reply-qa-remove">刪這則</button>
                </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="secondary small post-reply-qa-add">再加一則問答</button>
            </div>
            <div class="wide form-button-row">
              <button class="primary">儲存此套組</button>
            </div>
          </form>
          <div class="post-reply-set-tools">
            <form method="post">
              <input type="hidden" name="action" value="set_default_post_reply_set">
              <input type="hidden" name="set_id" value="<?=h($sid)?>">
              <button class="secondary" type="submit">設成預設</button>
            </form>
            <form method="post">
              <input type="hidden" name="action" value="duplicate_post_reply_set">
              <input type="hidden" name="set_id" value="<?=h($sid)?>">
              <button class="secondary" type="submit">複製一套</button>
            </form>
            <?php if ($isFactory): ?>
            <form method="post" onsubmit="return confirm('還原這套內建文案？你改過的內容會被蓋掉。');">
              <input type="hidden" name="action" value="restore_post_reply_set">
              <input type="hidden" name="set_id" value="<?=h($sid)?>">
              <button class="secondary" type="submit">還原內建文案</button>
            </form>
            <?php endif; ?>
            <form method="post" onsubmit="return confirm('確定刪除這套發文問答？');">
              <input type="hidden" name="action" value="delete_post_reply_set">
              <input type="hidden" name="set_id" value="<?=h($sid)?>">
              <button class="danger" type="submit">刪除套組</button>
            </form>
            <button type="button" class="secondary copy-set-qa" data-qa-text="<?=h(render_post_reply_qa_pack($postReplyPreviewSchedule, $postReplyPreviewProduct, $postReplySets, $sid))?>">複製問答包</button>
          </div>
        </div>
      </details>
      <?php endforeach; ?>
    </div>

    <div class="post-reply-matcher">
      <div class="section-head compact"><h3>貼上買家訊息，帶出對應回答</h3><span>選一套後貼留言，系統用問法／關鍵字對到最接近的回答，方便直接回。</span></div>
      <label>用哪一套<select id="postReplyMatchSet"><?php foreach ($postReplySets as $postSet): ?><option value="<?=h($postSet['id'] ?? '')?>"><?=h($postSet['name'] ?? '套組')?></option><?php endforeach; ?></select></label>
      <label class="wide">買家訊息<textarea id="postReplyMatchQuery" rows="4" placeholder="例如：現在有現貨嗎？可以面交嗎？"></textarea></label>
      <div id="postReplyMatchHits" class="post-reply-match-hits muted">貼上留言後會顯示建議回答。</div>
    </div>
    <script type="application/json" id="postReplySetsJson"><?=json_encode($postReplySets, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?></script>
    <script type="application/json" id="postReplyPreviewTokensJson"><?=json_encode(post_reply_tokens($postReplyPreviewSchedule, $postReplyPreviewProduct), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?></script>
  </section>

  <section class="ops-card ops-tab" id="settlement">
    <div class="section-head"><h2>得標 / 結算表</h2><span>得標儲存時會自動建立或更新會員資料</span></div>
    <div class="buyer-reconcile-panel">
      <div class="section-head compact"><h3>依得標人對帳 / 併單</h3><span>搜尋得標人後查看他得標的所有品項，含主圖、數量、金額，可複製給客戶核對。</span></div>
      <form method="get" action="operations.php#settlement" class="inline-actions buyer-reconcile-filter">
        <label>得標人搜尋<input name="buyer_q" value="<?=h($buyerQ)?>" placeholder="姓名 / Facebook / 電話"></label>
        <button class="secondary">查詢得標人</button>
        <a class="button-like" href="operations.php#settlement">清除</a>
      </form>
      <?php $buyerGroupShown = 0; foreach($orderGroups as $groupKey => $group): ?>
        <?php
          $buyer = $group['buyer'];
          $buyerHay = implode(' ', [$buyer['name'] ?? '', $buyer['facebook'] ?? '', $buyer['phone'] ?? '', $buyer['address'] ?? '']);
          if ($buyerQ !== '' && mb_stripos($buyerHay, $buyerQ, 0, 'UTF-8') === false) continue;
          $buyerGroupShown++;
          $copyLines = [];
          $copyLines[] = '得標品項對帳';
          $copyLines[] = '得標人：' . ($buyer['name'] ?: '-');
          if (!empty($buyer['facebook'])) $copyLines[] = 'Facebook：' . $buyer['facebook'];
          if (!empty($buyer['phone'])) $copyLines[] = '電話：' . $buyer['phone'];
          $copyLines[] = '----------------';
          foreach($group['items'] as $idx => $item) {
              $ss=$item['schedule']; $pp=$item['product']; $tt=$item['totals'];
              $spec = trim(($ss['product_color'] ?? ($pp['color'] ?? '')) . ' / ' . ($ss['product_size'] ?? ($pp['size'] ?? '')) . ' / ' . ($ss['product_spec'] ?? ($pp['spec'] ?? '')), ' /');
              $copyLines[] = ($idx+1) . '. ' . ($ss['product_id'] ?? '') . ' ' . ($ss['product_title'] ?? ($pp['title'] ?? '')) . ($spec ? '（' . $spec . '）' : '') . ' x ' . max(1,(int)($ss['quantity']??1)) . '，應收 ' . money($tt['receivable']);
          }
          $copyLines[] = '----------------';
          $copyLines[] = '合計應收：' . money($group['receivable']);
          $copyLines[] = '已收：' . money($group['paid']);
          $copyLines[] = '未收：' . money($group['unpaid']);
          $copyText = implode("\n", $copyLines);
        ?>
        <div class="buyer-reconcile-card">
          <div class="buyer-reconcile-head">
            <div><b><?=h($buyer['name'] ?: ($buyer['facebook'] ?: $buyer['phone']))?></b><div class="muted">Facebook：<?=h($buyer['facebook'] ?: '-')?>　電話：<?=h($buyer['phone'] ?: '-')?></div></div>
            <div class="buyer-total"><strong><?=money($group['receivable'])?></strong><small>未收 <?=money($group['unpaid'])?></small></div>
          </div>
          <div class="table-wrap"><table class="buyer-reconcile-table"><thead><tr><th>圖</th><th>商品</th><th>規格</th><th>日期</th><th>數量</th><th>應收</th><th>付款</th><th>出貨</th></tr></thead><tbody>
            <?php foreach($group['items'] as $item): $ss=$item['schedule']; $pp=$item['product']; $tt=$item['totals']; $img=$ss['schedule_image']??($pp['image']??''); ?>
              <tr>
                <td><?php if($img): ?><img class="thumb zoomable" src="<?=h($img)?>"><?php endif; ?></td>
                <td><b><?=h($ss['product_id']??'')?></b><br><?=h($ss['product_title']??($pp['title']??''))?></td>
                <td><?=h(trim(($ss['product_color']??($pp['color']??'')).' / '.($ss['product_size']??($pp['size']??'')).' / '.($ss['product_spec']??($pp['spec']??'')), ' /'))?></td>
                <td><?=h(substr((string)($ss['close_at']??$ss['publish_at']??''),0,16))?></td>
                <td><?=h($ss['quantity']??1)?></td>
                <td><?=money($tt['receivable'])?></td>
                <td><?=h($ss['payment_status']??'未付款')?></td>
                <td><?=h($ss['shipping_status']??'未出貨')?></td>
              </tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <label class="wide">對帳文字<textarea rows="7" readonly><?=h($copyText)?></textarea></label>
          <div class="form-actions"><button type="button" class="secondary copy-reconcile-message">複製對帳文字</button><a class="button-like" href="#orders" data-jump-tab="orders">到記單出貨</a></div>
        </div>
      <?php endforeach; ?>
      <?php if ($buyerGroupShown === 0): ?><div class="alert">目前沒有符合的得標人資料。請先在下方批次指定得標人，或到結算編輯填入得標人。</div><?php endif; ?>
    </div>

<form method="post" class="winner-batch-panel" onsubmit="return confirm('確定要把勾選品項指定給這位得標人，並送到記單出貨？');">
      <input type="hidden" name="action" value="assign_winner_batch">
      <div class="winner-batch-head">
        <div>
          <h3>批次指定得標人 / 送記單出貨</h3>
          <p class="muted">先選會員或輸入得標人資料，再勾選下方品項；送出後會寫入得標人，並在「記單出貨」依客戶自動分組。</p>
        </div>
        <button class="primary">指定得標人並送記單</button>
      </div>
      <div class="winner-batch-grid">
        <label>會員帶入
          <select name="batch_member_id" class="batch-member-picker">
            <option value="">新會員或不指定</option>
            <?php foreach($members as $m): ?>
              <option value="<?=h($m['id'])?>" data-name="<?=h($m['name']??'')?>" data-facebook="<?=h($m['facebook']??'')?>" data-phone="<?=h($m['phone']??'')?>" data-address="<?=h($m['address']??'')?>">
                <?=h(trim(($m['name']??'').' / '.($m['facebook']??'').' / '.($m['phone']??''), ' /'))?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>得標者姓名<input name="batch_winner" data-batch-member-field="name" placeholder="輸入或由會員帶入"></label>
        <label>Facebook<input name="batch_winner_facebook" data-batch-member-field="facebook" placeholder="FB 名稱"></label>
        <label>電話<input name="batch_winner_phone" data-batch-member-field="phone" placeholder="手機"></label>
        <label class="wide">地址<input name="batch_winner_address" data-batch-member-field="address" placeholder="出貨地址，可留空後續補"></label>
        <label>記單狀態
          <select name="batch_order_status">
            <option>待打單</option><option>待出貨</option><option>備貨中</option><option>已出貨</option><option>完成</option><option>取消</option>
          </select>
        </label>
        <label>備貨狀態
          <select name="batch_prep_status">
            <option>未備貨</option><option>備貨中</option><option>已備貨</option><option>缺貨</option>
          </select>
        </label>
      </div>
      <div class="bulk-bar winner-batch-toolbar">
        <label class="check"><input type="checkbox" data-select-all="winner_schedule_ids[]"> 全選下方品項</label>
        <span class="muted">已填得標人的品項也可重新指定；送出後會出現在記單出貨。</span>
      </div>
      <div class="table-wrap">
        <table class="winner-batch-table">
          <thead><tr><th>選</th><th>圖</th><th>場次/產品</th><th>目前得標者</th><th>數量</th><th>得標金額</th><th>應收</th><th>記單</th></tr></thead>
          <tbody>
          <?php foreach($shown as $s): $p=product_by_id($products,$s['product_id']??''); $t=totals($s,$p); $img=$s['schedule_image']??($p['image']??''); ?>
            <tr class="<?= buyer_key(['name'=>$s['winner']??'', 'facebook'=>$s['winner_facebook']??'', 'phone'=>$s['winner_phone']??'']) === '' ? 'needs-buyer' : 'has-buyer' ?>">
              <td><input type="checkbox" name="winner_schedule_ids[]" value="<?=h($s['id'])?>"></td>
              <td><?php if($img): ?><img class="thumb" src="<?=h($img)?>"><?php endif; ?></td>
              <td><b><?=h($s['product_id']??'')?></b> <?=h($s['product_title']??($p['title']??''))?><div class="small text-muted"><?=h(substr((string)($s['publish_at']??''),0,16))?></div></td>
              <td><?=h(trim(($s['winner']??'').' / '.($s['winner_facebook']??'').' / '.($s['winner_phone']??''), ' /') ?: '尚未指定')?></td>
              <td><?=h($s['quantity']??1)?></td>
              <td><?=money($s['winning_price']??0)?></td>
              <td><?=money($t['receivable'])?></td>
              <td><span class="status-pill"><?=h($s['order_status']??'待記單')?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>

    <div class="payment-offset-panel">
      <h3>收款沖帳</h3>
      <p class="muted">先選同一得標者的未收款品項，輸入匯款日期時間與匯款金額，系統會依勾選順序沖掉目前累計未收金額，並寫入收款紀錄。</p>
      <?php if (!$paymentGroups): ?><div class="alert">目前沒有可沖帳的未收款得標資料。</div><?php endif; ?>
      <?php foreach(array_slice($paymentGroups, 0, 12) as $g): ?>
        <form method="post" class="payment-offset-card" onsubmit="return confirm('確定要用這筆匯款沖帳勾選的得標品項？');">
          <input type="hidden" name="action" value="apply_payment_offset">
          <div class="payment-offset-head">
            <div><b><?=h($g['buyer']['name'] ?: ($g['buyer']['facebook'] ?: $g['buyer']['phone']))?></b><div class="small text-muted">未收 <?=money($g['unpaid'])?>，品項 <?=h(count($g['items']))?> 筆</div></div>
            <div class="payment-inputs">
              <label>匯款日期時間<input name="payment_paid_at" type="datetime-local" value="<?=h(date('Y-m-d\TH:i'))?>"></label>
              <label>匯款金額<input name="payment_amount" type="number" min="0" step="1" value="<?=h($g['unpaid'])?>"></label>
              <label>方式<input name="payment_method" value="匯款"></label>
            </div>
          </div>
          <div class="table-wrap"><table class="payment-offset-table"><thead><tr><th>沖帳</th><th>品項</th><th>日期</th><th>應收</th><th>已收</th><th>未收</th></tr></thead><tbody>
            <?php foreach($g['items'] as $item): $ss=$item['schedule']; $tt=$item['totals']; ?>
              <tr><td><input type="checkbox" name="payment_schedule_ids[]" value="<?=h($ss['id'] ?? '')?>" checked></td><td><b><?=h($ss['product_id'] ?? '')?></b> <?=h($ss['product_title'] ?? '')?></td><td><?=h(substr((string)($ss['close_at'] ?? $ss['publish_at'] ?? ''),0,16))?></td><td><?=money($tt['receivable'])?></td><td><?=money($tt['paid'])?></td><td><b class="danger-text"><?=money($tt['unpaid'])?></b></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
          <label class="wide">沖帳備註<input name="payment_note" placeholder="例如：銀行後五碼、匯款人、對帳備註"></label>
          <button class="primary">套用沖帳</button>
        </form>
      <?php endforeach; ?>
    </div>

    <form method="get" class="inline-actions">
      <label>付款狀態<select name="pay"><option value="">全部</option><?php foreach(['未付款','已付款','部分付款','取消'] as $v): ?><option <?=($filterPay===$v?'selected':'')?>><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>出貨狀態<select name="ship"><option value="">全部</option><?php foreach(['未出貨','備貨中','已出貨','完成'] as $v): ?><option <?=($filterShip===$v?'selected':'')?>><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>記單狀態<select name="order"><option value="">全部</option><?php foreach(['待記單','累計中','待打單','待出貨','已出貨','完成','退回處理','取消'] as $v): ?><option <?=($filterOrder===$v?'selected':'')?>><?=h($v)?></option><?php endforeach; ?></select></label>
      <button class="secondary">篩選</button>
    </form>
    <form method="post" class="bulk-form" data-confirm="確定刪除選取排程？會扣回預約庫存。"><input type="hidden" name="action" value="delete_schedules"><div class="bulk-bar"><label class="check"><input type="checkbox" data-select-all="schedule_ids[]"> 全選</label><button class="danger-button">刪除選取排程</button></div>
    <div class="table-wrap"><table class="settle-table"><thead><tr><th>選</th><th>圖</th><th>場次/商品</th><th>得標者</th><th>Facebook</th><th>電話</th><th>數量</th><th>得標金額</th><th>含稅</th><th>稅金</th><th>運費</th><th>其他</th><th>應收</th><th>已收</th><th>未收</th><th>成本</th><th>毛利</th><th>付款</th><th>出貨</th><th>記單</th><th>物流/單號</th><th>備註</th><th>操作</th></tr></thead><tbody>
    <?php foreach($shown as $s): $p=product_by_id($products,$s['product_id']??''); $t=totals($s,$p); $img=$s['schedule_image']??($p['image']??''); ?>
      <tr>
        <td><input type="checkbox" name="schedule_ids[]" value="<?=h($s['id'])?>"></td>
        <td><?php if($img): ?><img class="thumb" src="<?=h($img)?>"><?php endif; ?></td>
        <td><?=h(($s['publish_at']??'').' / '.($s['product_id']??''))?></td>
        <td><?=h($s['winner']??'')?></td><td><?=h($s['winner_facebook']??'')?></td><td><?=h($s['winner_phone']??'')?></td>
        <td><?=h($s['quantity']??1)?></td><td><?=money($s['winning_price']??0)?></td><td><?=(!empty($s['tax_included']) && (string)$s['tax_included']!=='0')?'含稅':'未稅'?></td><td><?=money($t['tax'])?></td><td><?=money($s['shipping_fee']??0)?></td><td><?=money($s['other_fee']??0)?></td><td><?=money($t['receivable'])?></td><td><?=money($t['paid'])?></td><td><?=money($t['unpaid'])?></td><td><?=money($t['cost'])?></td><td><?=money($t['profit'])?></td><td><?=h($s['payment_status']??'未付款')?></td><td><?=h($s['shipping_status']??'未出貨')?></td><td><span class="status-pill"><?=h($s['order_status']??'待記單')?></span></td><td><?=h(trim(($s['logistics_company']??'').' '.($s['tracking_no']??'').' '.($s['invoice_no']??'')))?><br><span class="muted"><?=h(($s['product_serial']??($s['warranty_serial']??'')))?></span></td><td><?=h($s['settlement_note']??'')?></td><td><button type="button" class="secondary edit-settlement" data-id="<?=h($s['id'])?>">編輯</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div></form>
  </section>

  <section class="ops-card ops-tab" id="settlement-edit">
    <h2>結算編輯 / 會員搜尋帶入 / 記單狀態</h2>
    <div class="settlement-flow-help">
      <strong>得標通知與買家填單入口放在這裡</strong>
      流程是：先在「排程上架」建立場次，截標後到「得標結算 / 結算編輯」填得標者、金額、付款與出貨資料；每一筆得標卡片下方會產生「買家專屬訂單填寫 / 查詢連結」與「得標通知文字」。目前先做複製文字與人工傳送，不會自動發 Facebook 或 LINE。
    </div>
    <datalist id="member-options"><?php foreach($members as $m): ?><option value="<?=h(($m['name']??'').' / '.($m['facebook']??'').' / '.($m['phone']??''))?>"></option><?php endforeach; ?></datalist>
    <?php foreach($schedules as $idx => $s): $p=product_by_id($products,$s['product_id']??''); $t=totals($s,$p); ?>
    <form method="post" enctype="multipart/form-data" class="settlement-card color-set-<?=((int)($idx ?? 0)%6)+1?>" id="settle-<?=h($s['id'])?>">
      <input type="hidden" name="action" value="save_settlement"><input type="hidden" name="schedule_id" value="<?=h($s['id'])?>">
      <div class="settlement-title"><b><?=h(($s['product_id']??'').' - '.($p['title']??''))?></b><button class="secondary">儲存結算</button></div>
      <div class="settlement-grid">
        <label>產品序號 / 保固序號<input name="product_serial" value="<?=h($s['product_serial']??($s['warranty_serial']??''))?>" placeholder="保固或出貨序號"></label>
        <label>物流公司<select name="logistics_company" class="logistics-company-select"><option value="" data-fee="0">未選擇</option><?php foreach($logistics as $lg): $ln=$lg['name']??''; $fee=(float)($lg['default_fee']??0); ?><option value="<?=h($ln)?>" data-fee="<?=h($fee)?>" <?= (($s['logistics_company']??'')===$ln?'selected':'') ?>><?=h($ln)?><?= $fee > 0 ? '（運費 '.h(money($fee)).'）' : '' ?></option><?php endforeach; ?></select></label>
        <label>會員搜尋參考<input list="member-options" placeholder="姓名 / Facebook / 電話"></label>
        <label>會員帶入<select name="member_id" class="member-picker"><option value="">新會員或不指定</option><?php foreach($members as $m): ?><option value="<?=h($m['id'])?>" data-name="<?=h($m['name']??'')?>" data-facebook="<?=h($m['facebook']??'')?>" data-phone="<?=h($m['phone']??'')?>" data-address="<?=h($m['address']??'')?>"><?=h(($m['name']??'').' / '.($m['facebook']??'').' / '.($m['phone']??''))?></option><?php endforeach; ?></select></label>
        <label>記單狀態<select name="order_status"><?php foreach(['待記單','累計中','待打單','待出貨','已出貨','完成','退回處理','取消'] as $v): ?><option <?=($s['order_status']??'待記單')===$v?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></label>
        <label>得標者<input name="winner" value="<?=h($s['winner']??'')?>"></label><label>Facebook<input name="winner_facebook" value="<?=h($s['winner_facebook']??'')?>"></label><label>電話<input name="winner_phone" value="<?=h($s['winner_phone']??'')?>"></label><label class="wide">地址<input name="winner_address" value="<?=h($s['winner_address']??'')?>"></label>
        <label>場次圖片<input name="schedule_image" value="<?=h($s['schedule_image']??'')?>"></label><label data-image-paste>補傳圖片（可貼上）<input name="schedule_image_upload" type="file" accept="image/*"></label><label>圖片備註<input name="schedule_image_note" value="<?=h($s['schedule_image_note']??'')?>"></label><label>Facebook 貼文網址<input name="post_url" value="<?=h($s['post_url']??'')?>"></label>
        <label>數量<input name="quantity" type="number" min="1" value="<?=h($s['quantity']??1)?>"></label><label>得標金額<input name="winning_price" type="number" value="<?=h($s['winning_price']??0)?>"></label><label>已收金額<input name="paid_amount" type="number" value="<?=h($s['paid_amount']??0)?>"></label><label>商品成本<input name="product_cost" type="number" value="<?=h($s['product_cost']??($p['cost']??0))?>"></label>
        <label>稅金設定<select name="tax_included"><option value="0" <?=empty($s['tax_included'])?'selected':''?>>未稅，不加 5%</option><option value="1" <?=(!empty($s['tax_included']) && (string)$s['tax_included']!=='0')?'selected':''?>>含稅，自動加 5%</option></select></label><label>自動稅金<input readonly value="<?=h(money($t['tax']))?>"></label><label>運費<input name="shipping_fee" class="shipping-fee-input" type="number" value="<?=h($s['shipping_fee']??0)?>"></label><label>其他費用<input name="other_fee" type="number" value="<?=h($s['other_fee']??0)?>"></label>
        <label>付款<select name="payment_status"><?php foreach(['未付款','已付款','部分付款','取消'] as $v): ?><option <?=($s['payment_status']??'未付款')===$v?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></label><label>出貨<select name="shipping_status"><?php foreach(['未出貨','備貨中','已出貨','完成'] as $v): ?><option <?=($s['shipping_status']??'未出貨')===$v?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></label><label>付款日期時間<input name="payment_date" type="datetime-local" value="<?=h(str_replace(' ', 'T', $s['payment_date']??''))?>"></label><label>出貨日期<input name="shipping_date" type="date" value="<?=h($s['shipping_date']??'')?>"></label>
        <label>物流單號<input name="tracking_no" value="<?=h($s['tracking_no']??'')?>"></label><label>打單/訂單編號<input name="invoice_no" value="<?=h($s['invoice_no']??'')?>"></label><label class="wide">記單備註<textarea name="settlement_note" rows="2"><?=h($s['settlement_note']??'')?></textarea></label>
      </div>
      <div class="settlement-summary"><span>得標 <b><?=money($t['base'])?></b></span><span>稅金 <b><?=money($t['tax'])?></b></span><span>應收 <b><?=money($t['receivable'])?></b></span><span>未收 <b><?=money($t['unpaid'])?></b></span><span>毛利 <b><?=money($t['profit'])?></b></span></div>
      <?php $buyerUrl = buyer_order_url($s); $noticeText = trim((string)($s['winner_notice_message']??'')) ?: (winner_notice_text($s, $p, $t) . ($buyerUrl ? "\n訂單填寫/查詢連結：" . $buyerUrl : '')); ?>
      <div class="settlement-notice-box">
        <label class="wide">買家專屬訂單填寫 / 查詢連結<input class="buyer-order-link" readonly value="<?=h($buyerUrl ?: '請先儲存一次結算，系統會建立買家專屬連結')?>"></label>
        <label class="wide">得標通知文字<textarea class="winner-notice" name="winner_notice_message" rows="8"><?=h($noticeText)?></textarea></label>
        <div class="settlement-notice-actions">
          <?php if($buyerUrl): ?><a class="button-like" href="<?=h($buyerUrl)?>" target="_blank" rel="noopener">開啟買家填單</a><?php endif; ?>
          <button type="button" class="secondary copy-order-link" <?= $buyerUrl ? '' : 'disabled' ?>>複製填單連結</button>
          <button type="button" class="secondary copy-notice">複製得標通知</button>
        </div>
      </div>
    </form>
    <?php endforeach; ?>
  </section>

  <section class="ops-card ops-tab" id="orders">
    <h2>記單 / 累計 / 打單出貨</h2>
    <p class="muted">這裡直接抓「得標結算」資料，依得標者自動分組。選客戶後可勾選品項、更新備貨/出貨狀態，並產生給得標人核對的文字。</p>
    <?php if (!$orderGroups): ?>
      <div class="order-empty-workflow">
        <h3>目前還沒有可記單出貨的得標資料</h3>
        <p>這裡不是手動新增空白單，而是自動抓「得標結算」裡已經填好得標者的品項，依照同一位客人自動分組。現在沒有資料，所以不會出現客人與品項。</p>
        <div class="order-flow-steps">
          <div class="order-flow-step"><b>1. 先建立排程</b>到「排程上架」建立競標場次，產品、數量、上架時間、截標時間要先有。</div>
          <div class="order-flow-step"><b>2. 截標後填得標</b>到「得標結算」或「結算編輯」填得標者、Facebook、電話、得標金額。</div>
          <div class="order-flow-step"><b>3. 儲存結算</b>儲存後系統會建立會員資料、買家填單連結與得標通知文字。</div>
          <div class="order-flow-step"><b>4. 回到記單出貨</b>同一位客人的得標品項會自動合併，才能勾選打單、備貨、出貨。</div>
        </div>
        <div class="order-empty-actions">
          <a class="button-like" href="#schedule" data-jump-tab="schedule">去排程上架</a>
          <a class="button-like" href="#settlement" data-jump-tab="settlement">去得標結算</a>
          <a class="button-like" href="#settlement-edit" data-jump-tab="settlement-edit">去結算編輯</a>
          <a class="button-like" href="#members" data-jump-tab="members">查看會員資料</a>
        </div>
      </div>
    <?php endif; ?>
    <?php foreach($orderGroups as $groupKey => $group): ?>
      <?php
        $buyer = $group['buyer'];
        $lines = [];
        $lines[] = '得標品項核對';
        $lines[] = '得標者：' . ($buyer['name'] ?: '-');
        if ($buyer['facebook']) $lines[] = 'Facebook：' . $buyer['facebook'];
        if ($buyer['phone']) $lines[] = '電話：' . $buyer['phone'];
        $lines[] = '品項：';
        foreach($group['items'] as $idx => $item) {
            $ss = $item['schedule']; $pp = $item['product']; $tt = $item['totals'];
            $spec = trim(($ss['product_color'] ?? ($pp['color'] ?? '')) . ' / ' . ($ss['product_size'] ?? ($pp['size'] ?? '')) . ' / ' . ($ss['product_spec'] ?? ($pp['spec'] ?? '')), ' /');
            $lines[] = ($idx + 1) . '. 產品編號 ' . ($ss['product_id'] ?? '') . '｜條碼 ' . ($ss['product_barcode'] ?? ($pp['barcode'] ?? '-')) . '｜序號 ' . ($ss['product_serial'] ?? ($ss['warranty_serial'] ?? '-')) . '｜' . ($ss['product_title'] ?? ($pp['title'] ?? '')) . ($spec ? '（' . $spec . '）' : '') . ' x ' . max(1, (int)($ss['quantity'] ?? 1)) . '，應收 ' . money($tt['receivable']);
        }
        $lines[] = '合計應收：' . money($group['receivable']);
        $lines[] = '已收：' . money($group['paid']);
        $lines[] = '未收：' . money($group['unpaid']);
        $copyText = implode("\n", $lines);
      ?>
      <div class="order-group-card">
        <div class="order-group-head">
          <div>
            <h3><?=h($buyer['name'] ?: '未填姓名')?> <span class="muted"><?=h($buyer['facebook'] ?? '')?></span></h3>
            <div class="small text-muted">電話：<?=h($buyer['phone'] ?: '-')?>　最近得標：<?=h($group['last_win_date'] ?: '-')?></div>
          </div>
            <form method="post" class="inline-form" onsubmit="return confirm('確定將此客戶勾選品項產生正式出貨單？');">
              <input type="hidden" name="action" value="create_delivery_note">
              <?php foreach($group['items'] as $item): ?><input type="hidden" name="delivery_schedule_ids[]" value="<?=h($item['schedule']['id'] ?? '')?>"><?php endforeach; ?>
              <button class="secondary small">此客戶轉出貨單</button>
            </form>
          <div class="order-total">
            <span>待處理 <?=h($group['count'])?> 筆</span>
            <strong><?=money($group['receivable'])?></strong>
            <small>未收 <?=money($group['unpaid'])?></small>
          </div>
        </div>
        <form method="post" class="order-batch-form">
          <input type="hidden" name="action" value="save_order_batch">
          <div class="table-wrap">
            <table class="order-items-table">
              <thead><tr><th><input type="checkbox" data-order-check-all></th><th>狀態</th><th>產品</th><th>規格</th><th>數量</th><th>得標/應收</th><th>付款</th><th>出貨</th><th>日期</th></tr></thead>
              <tbody>
                <?php foreach($group['items'] as $item): ?>
                  <?php $ss=$item['schedule']; $pp=$item['product']; $tt=$item['totals']; ?>
                  <tr>
                    <td><input type="checkbox" name="order_schedule_ids[]" value="<?=h($ss['id'] ?? '')?>" checked></td>
                    <td><?=h($ss['prep_status'] ?? '未備貨')?></td>
                    <td><b><?=h($ss['product_id'] ?? '')?></b><br><?=h($ss['product_title'] ?? ($pp['title'] ?? ''))?><br><span class="small text-muted">條碼：<?=h($ss['product_barcode'] ?? ($pp['barcode'] ?? '-'))?></span></td>
                    <td><?=h(trim(($ss['product_color'] ?? ($pp['color'] ?? '')).' / '.($ss['product_size'] ?? ($pp['size'] ?? '')).' / '.($ss['product_spec'] ?? ($pp['spec'] ?? '')), ' /') ?: '-')?></td>
                    <td><?=h(max(1,(int)($ss['quantity'] ?? 1)))?></td>
                    <td><?=money($ss['winning_price'] ?? 0)?><br><b><?=money($tt['receivable'])?></b></td>
                    <td><?=h($ss['payment_status'] ?? '未付款')?><br><span class="small text-muted">已收 <?=money($tt['paid'])?></span></td>
                    <td><?=h($ss['shipping_status'] ?? '未出貨')?><br><span class="small text-muted"><?=h($ss['tracking_no'] ?? '')?></span></td>
                    <td><?=h(substr((string)($ss['close_at'] ?? ''),0,10))?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="order-actions">
            <label>備貨狀態<select name="prep_status"><option value="">不變更</option><option>未備貨</option><option>已備貨</option><option>缺貨待確認</option></select></label>
            <label>記單狀態<select name="order_status"><option value="">不變更</option><option>待打單</option><option>待出貨</option><option>已出貨</option><option>完成</option><option>取消</option></select></label>
            <label>出貨狀態<select name="shipping_status"><option value="">不變更</option><option>未出貨</option><option>備貨中</option><option>已出貨</option><option>完成</option></select></label>
            <label>出貨日期<input name="shipping_date" type="date"></label>
            <label>物流公司<select name="logistics_company"><option value="">不變更</option><?php foreach($logistics as $lg): ?><option><?=h($lg['name']??'')?></option><?php endforeach; ?></select></label>
            <label>物流單號<input name="tracking_no" placeholder="可整批填入"></label>
            <button class="primary">更新勾選品項</button>
          </div>
        </form>
        <label class="wide">傳給得標人核對文字<textarea rows="7" readonly><?=h($copyText)?></textarea></label>
        <button type="button" class="secondary copy-order-message">複製核對文字</button>
      </div>
    <?php endforeach; ?>
    <div class="sub-card">
      <h3>已產生出貨單紀錄</h3>
      <p class="muted">從記單出貨轉出的正式出貨單會留在這裡，之後可再串公司出貨單與進銷存。</p>
      <div class="table-wrap"><table><thead><tr><th>出貨單號</th><th>客戶</th><th>品項</th><th>金額</th><th>狀態</th><th>時間</th></tr></thead><tbody>
        <?php foreach(array_reverse($deliveryNotes) as $dn): $b=$dn['buyer'] ?? []; ?>
          <tr>
            <td><b><?=h($dn['delivery_no'] ?? '')?></b></td>
            <td><?=h(($b['name'] ?? '') ?: (($b['facebook'] ?? '') ?: ($b['phone'] ?? '')))?></td>
            <td><?=h(count($dn['items'] ?? []))?> 筆</td>
            <td><?=money($dn['total'] ?? 0)?></td>
            <td><?=h($dn['status'] ?? '')?></td>
            <td><?=h(substr((string)($dn['created_at'] ?? ''),0,19))?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$deliveryNotes): ?><tr><td colspan="6" class="muted">尚未產生出貨單。</td></tr><?php endif; ?>
      </tbody></table></div>
    </div>
  </section>

  <section class="ops-card ops-tab" id="customer-shipping">
    <?php
      $ed = is_array($editingDelivery) ? $editingDelivery : [];
      $editingBuyer = is_array($ed['buyer'] ?? null) ? $ed['buyer'] : [];
      $editingItems = is_array($ed['items'] ?? null) ? $ed['items'] : [];
      $editingSalesNo = trim((string)($ed['delivery_no'] ?? ''));
      if (delivery_no_is_placeholder($editingSalesNo) || delivery_no_needs_repair($editingSalesNo)) $editingSalesNo = '';
      $shipPay = (string)($ed['payment_status'] ?? '未付款');
      $shipStat = (string)($ed['shipping_status'] ?? '未出貨');
      $shipLogi = (string)($ed['logistics_company'] ?? '');
      $clearShipHref = ($isEmbed ? '?embed=1' : '?') . '#customer-shipping';
      $salesOutRowIndex = 0;
    ?>
    <h2><?= $editingDelivery ? '修正銷售出庫單' : '銷售出庫單' ?></h2>
    <p class="muted"><?= $editingDelivery ? ('正在修改 <b>' . h($editingDelivery['delivery_no'] ?? '') . '</b>。儲存後會依明細調整庫存，單號維持原編號。') : '正式銷售出庫單：選客戶會帶入電話與地址，儲存後產生 <b>SELL-' . h(date('Ymd')) . '-001</b>。估價單轉來的出貨單是 <b>VAL-日期-流水</b>。' ?></p>
    <?php if ($editingDelivery): ?><p><a class="button-like small" href="<?=h($clearShipHref)?>">取消修改，改開新單</a></p><?php endif; ?>
    <form method="post" class="product-form" id="salesOutForm">
      <input type="hidden" name="action" value="sales_out">
      <?php if ($editingDelivery): ?><input type="hidden" name="sales_delivery_id" value="<?=h($editingDelivery['id'] ?? '')?>"><?php endif; ?>
      <label>出庫單號<input name="sales_doc_no" value="<?=h($editingSalesNo)?>" placeholder="空白由系統自動產生，例如 SELL-<?=h(date('Ymd'))?>-001" <?= $editingSalesNo !== '' ? 'readonly' : '' ?>></label>
      <label>單據日期<input type="date" name="sales_doc_date" value="<?=h($ed['date'] ?? date('Y-m-d'))?>"></label>
      <label>客戶名稱<input id="salesCustomerName" name="sales_customer_name" list="salesCustomerOptions" autocomplete="off" required placeholder="必填，輸入或選擇客戶，會帶入電話與地址" value="<?=h($editingBuyer['name'] ?? '')?>"></label>
      <datalist id="salesCustomerOptions"><?php foreach($members as $m): $salesContact = member_contact_fields($m); if (($salesContact['name'] ?? '') === '') continue; ?><option value="<?=h($salesContact['name'])?>"><?=h(trim(($salesContact['contact'] ?: ($m['organization_name'] ?? '')).' / '.($salesContact['phone'] ?? '').' / '.($salesContact['address'] ?? ''), ' /'))?></option><?php endforeach; ?></datalist>
      <label>電話<input id="salesCustomerPhone" name="sales_customer_phone" value="<?=h($editingBuyer['phone'] ?? '')?>"></label>
      <label class="wide">地址<input id="salesCustomerAddress" name="sales_customer_address" value="<?=h($editingBuyer['address'] ?? '')?>"></label>
      <label>經手人<input name="sales_handler" value="<?=h($ed['handler'] ?? current_operator())?>"></label>
      <label>部門 / 成本中心<input name="sales_department" value="<?=h($ed['department'] ?? '電商部')?>"></label>
      <label>付款狀態<select name="sales_payment_status"><?php foreach (['未付款','已付款','部分付款','月結'] as $opt): ?><option<?= $shipPay === $opt ? ' selected' : '' ?>><?=h($opt)?></option><?php endforeach; ?></select></label>
      <label>出貨狀態<select name="sales_shipping_status"><?php foreach (['未出貨','備貨中','已出貨','完成'] as $opt): ?><option<?= $shipStat === $opt ? ' selected' : '' ?>><?=h($opt)?></option><?php endforeach; ?></select></label>
      <label>物流公司<select name="sales_logistics_company"><option value="">未選擇</option><?php foreach($logistics as $lg): $lgName = (string)($lg['name'] ?? ''); ?><option<?= $shipLogi === $lgName ? ' selected' : '' ?>><?=h($lgName)?></option><?php endforeach; ?></select></label>
      <label>物流單號<input name="sales_tracking_no" value="<?=h($ed['tracking_no'] ?? '')?>"></label>
      <label>發票 / 憑證號碼<input name="sales_invoice_no" value="<?=h($ed['invoice_no'] ?? '')?>"></label>
      <label class="wide">產品條碼 / 關鍵字搜尋
        <input id="salesProductSearch" autocomplete="off" placeholder="掃條碼，或輸入產品編號、條碼、名稱、顏色、尺寸">
      </label>
      <div id="salesProductResults" class="wide product-search-results"></div>
      <div class="wide table-wrap">
        <table class="compact-table">
          <thead><tr><th>產品</th><th>條碼 / 規格</th><th>可用</th><th>數量</th><th>單價</th><th>小計</th><th>庫存名稱</th><th>貨架 / 倉位</th><th>備註</th><th>操作</th></tr></thead>
          <tbody id="salesOutRows">
            <?php if ($editingItems): $salesOutRowIndex = 0; foreach ($editingItems as $line):
              $pkey = trim((string)($line['product_id'] ?? $line['product_barcode'] ?? ''));
              $pidx = find_product_key($products, $pkey);
              $p = $pidx >= 0 ? $products[$pidx] : [];
              $qty = max(1, (int)($line['quantity'] ?? 1));
              $price = (float)($line['unit_price'] ?? 0);
              $availableNow = ($pidx >= 0 ? stock_available($p) : 0) + $qty;
              $productText = trim((string)($p['id'] ?? $pkey)) . (!empty($p['title'] ?? $line['product_title']) ? ' - ' . (string)($p['title'] ?? $line['product_title']) : '');
              $specText = trim(implode(' / ', array_filter([(string)($line['color'] ?? $p['color'] ?? ''), (string)($line['size'] ?? $p['size'] ?? ''), (string)($line['spec'] ?? $p['spec'] ?? '')], function($v){ return trim($v) !== ''; }))) ?: '-';
              $stockName = (string)($line['warehouse_name'] ?? $p['warehouse_name'] ?? '-');
              $positionText = trim((string)($line['shelf_code'] ?? $p['shelf_code'] ?? '') . ' ' . (string)($line['warehouse_location'] ?? $p['warehouse_location'] ?? '')) ?: '-';
            ?>
            <tr class="sales-out-line" data-product-id="<?=h($p['id'] ?? $pkey)?>">
              <td><b><?=h($productText)?></b><input type="hidden" name="sales_items[<?=$salesOutRowIndex?>][product_key]" value="<?=h($p['id'] ?? $pkey)?>"></td>
              <td><?=h($p['barcode'] ?? ($line['product_barcode'] ?? ''))?><br><small><?=h($specText)?></small></td>
              <td><?=h($availableNow)?></td>
              <td><input class="sales-line-qty" name="sales_items[<?=$salesOutRowIndex?>][qty]" type="number" min="1" max="<?=h($availableNow)?>" value="<?=h($qty)?>" required></td>
              <td><input class="sales-line-price" name="sales_items[<?=$salesOutRowIndex?>][unit_price]" type="number" min="0" step="0.01" value="<?=h($price)?>"></td>
              <td class="sales-line-subtotal"><?=money($qty * $price)?></td>
              <td><?=h($stockName)?></td>
              <td><?=h($positionText)?></td>
              <td><input name="sales_items[<?=$salesOutRowIndex?>][note]" placeholder="備註" value="<?=h($line['note'] ?? '')?>"></td>
              <td><button type="button" class="danger small remove-sales-line">移除</button></td>
            </tr>
            <?php $salesOutRowIndex++; endforeach; else: $salesOutRowIndex = 0; ?>
            <tr class="empty-sales-row"><td colspan="10" class="muted">請先掃描條碼或搜尋產品加入銷售明細。</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <label>折扣<input type="number" min="0" step="0.01" name="sales_discount" id="salesDocumentDiscount" value="<?=h($ed['discount'] ?? 0)?>"></label>
      <label>稅額<input type="number" min="0" step="0.01" name="sales_tax" id="salesDocumentTax" value="<?=h($ed['tax'] ?? 0)?>"></label>
      <label>運費<input type="number" min="0" step="0.01" name="sales_shipping_fee" id="salesDocumentShippingFee" value="<?=h($ed['shipping_fee'] ?? 0)?>"></label>
      <label>其他費用<input type="number" min="0" step="0.01" name="sales_other_fee" id="salesDocumentOtherFee" value="<?=h($ed['other_fee'] ?? 0)?>"></label>
      <div class="wide document-total-bar">
        <span>明細合計 <b id="salesLineTotal"><?=money($ed['line_total'] ?? 0)?></b></span>
        <span>整單合計 <b id="salesDocumentTotal"><?=money($ed['total'] ?? 0)?></b></span>
      </div>
      <label class="wide">整單備註<textarea name="sales_doc_note" rows="2" placeholder="出貨備註、付款條件、客戶特殊要求"><?=h($ed['note'] ?? '')?></textarea></label>
      <button class="primary"><?= $editingDelivery ? '儲存修正並更新庫存' : '儲存正式銷售出庫單並扣庫存' ?></button>
    </form>
    <hr>
    <h3>待出貨客戶彙整</h3>
    <div class="table-wrap"><table><thead><tr><th>客戶</th><th>電話</th><th>待出貨品項</th><th>應收</th><th>未收</th><th>操作</th></tr></thead><tbody>
      <?php foreach($unshippedByBuyer as $g): $b = $g['buyer'] ?? []; ?>
        <tr>
          <td><?=h($b['name'] ?? '')?></td>
          <td><?=h($b['phone'] ?? '')?></td>
          <td><?=h(count($g['items'] ?? []))?> 筆</td>
          <td><?=money($g['receivable'] ?? 0)?></td>
          <td><?=money($g['unpaid'] ?? 0)?></td>
          <td><a class="button-like small" href="#orders" data-jump-tab="orders">去記單出貨</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$unshippedByBuyer): ?><tr><td colspan="6" class="muted">目前沒有未出貨客戶。</td></tr><?php endif; ?>
    </tbody></table></div>

    <div class="sub-card" style="margin-top:18px;">
      <h3>正式出貨單</h3>
      <p class="muted">可依客戶、電話、出貨單號、狀態篩選。每一張都看得到客戶與聯絡資料，可列印、修正或刪除。</p>
      <form method="get" class="product-form">
        <?php if ($isEmbed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label>關鍵字<input name="ship_q" value="<?=h($shipFilterQ)?>" placeholder="單號、客戶、品名、物流單"></label>
        <label>客戶<input name="ship_customer" value="<?=h($shipFilterCustomer)?>" placeholder="客戶名稱"></label>
        <label>電話<input name="ship_phone" value="<?=h($shipFilterPhone)?>" placeholder="電話"></label>
        <label>出貨單號<input name="ship_no" value="<?=h($shipFilterNo)?>" placeholder="SELL- 或 VAL-"></label>
        <label>出貨狀態<select name="ship_status"><option value="">全部</option><?php foreach (['未出貨','備貨中','已出貨','完成'] as $opt): ?><option value="<?=h($opt)?>"<?= $shipFilterStatus === $opt ? ' selected' : '' ?>><?=h($opt)?></option><?php endforeach; ?></select></label>
        <label>日期起<input type="date" name="ship_from" value="<?=h($shipFilterFrom)?>"></label>
        <label>日期迄<input type="date" name="ship_to" value="<?=h($shipFilterTo)?>"></label>
        <button class="primary" type="submit">篩選出貨單</button>
        <a class="button-like" href="<?=h($clearShipHref)?>">清除篩選</a>
      </form>
      <p class="muted">顯示 <?=h(count($filteredDeliveryNotes))?> / <?=h(count($deliveryNotes))?> 張</p>
      <div class="table-wrap"><table><thead><tr><th>出貨單號</th><th>客戶</th><th>電話</th><th>地址</th><th>品項</th><th>金額</th><th>付款</th><th>出貨狀態</th><th>時間</th><th>操作</th></tr></thead><tbody>
        <?php foreach(array_reverse($filteredDeliveryNotes) as $dn):
          $b = is_array($dn['buyer'] ?? null) ? $dn['buyer'] : [];
          $titles = [];
          foreach (($dn['items'] ?? []) as $it) {
              if (!is_array($it)) continue;
              $title = trim((string)($it['product_title'] ?? $it['product_id'] ?? ''));
              if ($title !== '') $titles[] = $title;
          }
          $itemLabel = !$titles ? '0 筆' : ($titles[0] . (count($titles) > 1 ? ' 等' . count($titles) . '筆' : ''));
          $editQuery = $_GET;
          if ($isEmbed) $editQuery['embed'] = '1';
          $editQuery['edit_delivery'] = (string)($dn['id'] ?? '');
          unset($editQuery['print_delivery'], $editQuery['autoprint']);
          $editHref = '?' . http_build_query($editQuery) . '#customer-shipping';
          $printHref = '?print_delivery=' . urlencode((string)($dn['id'] ?? '')) . '&autoprint=1';
        ?>
          <tr>
            <td><b><?=h($dn['delivery_no'] ?? '')?></b><br><small><?=h(($dn['source'] ?? '') === 'quotation' ? ('估價轉出貨 ' . ($dn['quote_no'] ?? '')) : '正規出貨')?></small></td>
            <td><?=h($b['name'] ?? '') ?: '<span class="muted">未填客戶</span>'?></td>
            <td><?=h($b['phone'] ?? '')?></td>
            <td><?=h($b['address'] ?? '')?></td>
            <td><?=h($itemLabel)?></td>
            <td><?=money($dn['total'] ?? 0)?></td>
            <td><?=h($dn['payment_status'] ?? '')?></td>
            <td><?=h($dn['status'] ?? ($dn['shipping_status'] ?? ''))?></td>
            <td><?=h(substr((string)($dn['updated_at'] ?? ($dn['created_at'] ?? '')),0,19))?></td>
            <td>
              <a class="button-like small" href="<?=h($printHref)?>" target="_blank" rel="noopener">列印</a>
              <a class="button-like small" href="<?=h($editHref)?>">編輯</a>
              <form method="post" style="display:inline" onsubmit="return confirm('刪除這張出貨單會回補已扣庫存，確定嗎？');">
                <input type="hidden" name="action" value="delete_delivery_note">
                <input type="hidden" name="delivery_id" value="<?=h($dn['id'] ?? '')?>">
                <button type="submit" class="danger small">刪除</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$filteredDeliveryNotes): ?><tr><td colspan="10" class="muted"><?= $deliveryNotes ? '沒有符合篩選的出貨單。' : '尚未有正式出貨單。' ?></td></tr><?php endif; ?>
      </tbody></table></div>
    </div>
  </section>

  <section class="ops-card ops-tab" id="quotations">
    <div class="section-head">
      <div>
        <h2>估價單</h2>
        <p class="muted">直接在電商營運畫面建立、查詢與處理總部估價單；轉成正式出貨單後會同步到本後台的銷售單據。</p>
      </div>
      <button class="button-like" type="button" onclick="loadEmbeddedQuotation(true)">重新載入估價單</button>
    </div>
    <style>
      .quotation-embed-shell{border:1px solid #dbe5f2;border-radius:8px;background:#f8fafc;overflow:hidden;margin-bottom:18px}
      .quotation-embed-status{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;border-bottom:1px solid #dbe5f2;background:#eef4fb;color:#334155;font-weight:800}
      .quotation-embed-frame{display:block;width:100%;height:980px;border:0;background:#f1f5f9}
      @media(max-width:900px){.quotation-embed-frame{height:1100px}.quotation-embed-status{align-items:flex-start;flex-direction:column}}
    </style>
    <div class="quotation-embed-shell">
      <div class="quotation-embed-status">
        <span id="embeddedQuotationStatus">開啟本頁時會直接載入總部估價單資料。</span>
        <span class="status-pill">共用總部資料與權限</span>
      </div>
      <iframe id="embeddedQuotationFrame" class="quotation-embed-frame" data-src="../admin.php#quotationManager" title="總部估價單管理"></iframe>
    </div>
    <h3>已轉入電商的正式出貨單</h3>
    <div class="table-wrap"><table><thead><tr><th>出貨單號</th><th>估價單號</th><th>客戶</th><th>品項</th><th>金額</th><th>狀態</th><th>時間</th></tr></thead><tbody>
      <?php foreach(array_reverse($deliveryNotes) as $dn): if (($dn['source'] ?? '') !== 'quotation') continue; $b = $dn['buyer'] ?? []; ?>
        <tr>
          <td><b><?=h($dn['delivery_no'] ?? '')?></b></td>
          <td><?=h($dn['quote_no'] ?? '')?></td>
          <td><?=h(($b['name'] ?? '') ?: (($b['phone'] ?? '') ?: ($b['facebook'] ?? '')))?></td>
          <td><?=h(count($dn['items'] ?? []))?> 筆</td>
          <td><?=money($dn['total'] ?? 0)?></td>
          <td><?=h($dn['status'] ?? ($dn['shipping_status'] ?? ''))?></td>
          <td><?=h(substr((string)($dn['updated_at'] ?? ($dn['created_at'] ?? '')),0,19))?></td>
        </tr>
      <?php endforeach; ?>
      <?php $hasQuoteDelivery = false; foreach($deliveryNotes as $dn){ if (($dn['source'] ?? '') === 'quotation') { $hasQuoteDelivery = true; break; } } ?>
      <?php if(!$hasQuoteDelivery): ?><tr><td colspan="7" class="muted">目前尚未有估價單轉出的正式出貨單；可直接使用上方估價單工作區建立或轉單。</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
  <section class="ops-card ops-tab" id="members">
    <div class="section-head">
      <div>
        <h2>會員 / 買家資料中心</h2>
        <p class="muted">這裡是得標者會員主檔，會串得標結算、記單出貨與黑名單風險紀錄。搜尋姓名、Facebook、電話、地址或備註都能查。</p>
      </div>
    </div>

    <?php
      $blackCount = count(array_filter($members, function($m) { return ($m['blacklist_status'] ?? '正常') === '黑名單'; }));
      $watchCount = count(array_filter($members, function($m) { return ($m['blacklist_status'] ?? '正常') === '觀察'; }));
      $memberOpenTotal = array_sum(array_map(function($m) { return (int)($m['open_order_count'] ?? 0); }, $members));
      $memberUnpaidTotal = array_sum(array_map(function($m) { return (float)($m['unpaid_amount'] ?? 0); }, $members));
    ?>
    <div class="member-summary-grid">
      <div class="metric"><span>會員數</span><strong><?=h(count($members))?> 位</strong></div>
      <div class="metric"><span>待處理訂單</span><strong><?=h($memberOpenTotal)?> 筆</strong></div>
      <div class="metric"><span>未收款</span><strong><?=money($memberUnpaidTotal)?></strong></div>
      <div class="metric risk"><span>黑名單 / 觀察</span><strong><?=h($blackCount)?> / <?=h($watchCount)?></strong></div>
    </div>

    <div class="member-workbench">
      <form method="get" action="operations.php#members" class="member-search-card">
        <h3>搜尋會員</h3>
        <label>關鍵字<input name="member_q" value="<?=h($memberQ)?>" placeholder="姓名 / Facebook / 電話 / 地址 / 備註"></label>
        <label>風險篩選<select name="member_risk">
          <option value="">全部會員</option>
          <?php foreach(['正常','觀察','黑名單','一般','中風險','高風險'] as $v): ?>
            <option value="<?=h($v)?>" <?=$memberRisk===$v?'selected':''?>><?=h($v)?></option>
          <?php endforeach; ?>
        </select></label>
        <div class="form-actions"><button class="primary">搜尋會員</button><a class="button-like" href="operations.php#members">清除</a></div>
      </form>

      <form method="post" class="member-editor-card">
        <input type="hidden" name="action" value="save_member">
        <h3>新增 / 更新會員</h3>
        <div class="mini-form">
          <label>姓名<input name="name" placeholder="買家姓名"></label>
          <label>Facebook<input name="facebook" placeholder="FB 名稱"></label>
          <label>電話<input name="phone" placeholder="手機"></label>
          <label class="wide">地址<input name="address" placeholder="出貨地址"></label>
          <label>黑名單狀態<select name="blacklist_status"><option>正常</option><option>觀察</option><option>黑名單</option></select></label>
          <label>風險等級<select name="risk_level"><option>一般</option><option>中風險</option><option>高風險</option></select></label>
          <label class="wide">黑名單 / 風險原因<textarea name="blacklist_reason" rows="2" placeholder="例如：棄標、疑似詐騙、匯款異常、退貨爭議"></textarea></label>
          <label class="wide">備註<textarea name="note" rows="2"></textarea></label>
        </div>
        <button class="primary">儲存會員</button>
      </form>
    </div>

    <div class="table-wrap member-table-wrap">
      <table class="member-table">
        <thead><tr><th>會員</th><th>聯絡/地址</th><th>風險</th><th>交易統計</th><th>未收/待處理</th><th>備註</th><th>快速更新</th></tr></thead>
        <tbody>
        <?php foreach($memberShownPage as $m): ?>
          <tr class="<?=($m['blacklist_status'] ?? '正常') === '黑名單' ? 'is-blacklisted' : (($m['blacklist_status'] ?? '正常') === '觀察' ? 'is-watch' : '')?>">
            <td><b><?=h($m['name'] ?: '-')?></b><br><span class="small text-muted"><?=h($m['facebook'] ?: '-')?></span></td>
            <td>電話：<?=h($m['phone'] ?: '-')?><br>地址：<?=h($m['address'] ?: '-')?></td>
            <td><span class="status-pill"><?=h($m['blacklist_status'] ?? '正常')?></span><br><span class="small text-muted"><?=h($m['risk_level'] ?? '一般')?></span><?php if(!empty($m['blacklist_reason'])): ?><div class="risk-reason"><?=h($m['blacklist_reason'])?></div><?php endif; ?></td>
            <td>最近：<?=h($m['last_win_date'] ?: '-')?><br>累計：<?=money($m['total_winning_amount'] ?? 0)?><br>次數：<?=h($m['total_win_count'] ?? 0)?></td>
            <td>待處理：<?=h($m['open_order_count'] ?? 0)?><br><b class="danger-text">未收：<?=money($m['unpaid_amount'] ?? 0)?></b></td>
            <td><?=nl2br(h($m['note'] ?? ''))?></td>
            <td>
              <form method="post" class="member-inline-form">
                <input type="hidden" name="action" value="save_member">
                <input type="hidden" name="member_id" value="<?=h($m['id'] ?? '')?>">
                <input type="hidden" name="created_at" value="<?=h($m['created_at'] ?? '')?>">
                <input name="name" value="<?=h($m['name'] ?? '')?>" placeholder="姓名">
                <input name="facebook" value="<?=h($m['facebook'] ?? '')?>" placeholder="Facebook">
                <input name="phone" value="<?=h($m['phone'] ?? '')?>" placeholder="電話">
                <input name="address" value="<?=h($m['address'] ?? '')?>" placeholder="地址">
                <select name="blacklist_status"><?php foreach(['正常','觀察','黑名單'] as $v): ?><option <?=$v===($m['blacklist_status'] ?? '正常')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select>
                <select name="risk_level"><?php foreach(['一般','中風險','高風險'] as $v): ?><option <?=$v===($m['risk_level'] ?? '一般')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select>
                <textarea name="blacklist_reason" rows="2" placeholder="風險原因"><?=h($m['blacklist_reason'] ?? '')?></textarea>
                <textarea name="note" rows="2" placeholder="備註"><?=h($m['note'] ?? '')?></textarea>
                <button class="secondary">更新</button>
            <button class="secondary" name="generate_reminder" value="1">產生提醒草稿</button>
            <button class="secondary" name="mark_reminded" value="1">標記已提醒</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if($opsMemberPages > 1): ?>
      <div class="pager"><span>第 <?=h($opsMemberPage)?> / <?=h($opsMemberPages)?> 頁，每頁 10 筆</span><?php if($opsMemberPage > 1): ?><a class="secondary small" href="<?=h($opsMemberPageUrl($opsMemberPage - 1))?>">上一頁</a><?php endif; ?><?php if($opsMemberPage < $opsMemberPages): ?><a class="secondary small" href="<?=h($opsMemberPageUrl($opsMemberPage + 1))?>">下一頁</a><?php endif; ?></div>
    <?php endif; ?>
  </section>

    <section class="ops-card ops-tab" id="logistics">
    <h2>物流管理</h2>
    <p class="muted">先建立常用物流公司與預設運費，結算編輯與記單出貨會用選單帶入；員工新增或更新會留下操作帳號與時間。</p>
    <form method="post" class="inline-actions">
      <input type="hidden" name="action" value="save_logistics_company">
      <label>物流公司名稱<input name="logistics_name" placeholder="例如：7-11、全家、黑貓宅急便"></label>
      <label>預設運費<input name="default_fee" type="number" min="0" step="1" value="0" placeholder="例如：60、80、120"></label>
      <button class="primary">新增物流</button>
    </form>
    <form id="logisticsBulkDeleteForm" method="post" onsubmit="return confirm('確定刪除勾選的物流公司？');"><input type="hidden" name="action" value="delete_logistics_companies"></form><div class="bulk-bar"><label class="check"><input type="checkbox" data-select-all="logistics_ids[]"> 全選物流公司</label><button class="danger-button" type="submit" form="logisticsBulkDeleteForm">刪除勾選物流</button></div><div class="table-wrap"><table><thead><tr><th>選</th><th>物流公司</th><th>預設運費</th><th>操作</th></tr></thead><tbody>
      <?php foreach($logistics as $lg): ?>
        <tr>
          <td><input type="checkbox" name="logistics_ids[]" value="<?=h($lg['id']??'')?>" form="logisticsBulkDeleteForm"></td>
          <td>
            <form method="post" class="inline-form logistics-row-form">
              <input type="hidden" name="action" value="save_logistics_company">
              <input type="hidden" name="logistics_id" value="<?=h($lg['id']??'')?>">
              <input name="logistics_name" value="<?=h($lg['name']??'')?>">
          </td>
          <td><input name="default_fee" type="number" min="0" step="1" value="<?=h($lg['default_fee']??0)?>"></td>
          <td>
              <button class="secondary small">更新</button>
            </form>
            <form method="post" class="inline-form" onsubmit="return confirm('確定刪除這個物流選項？');"><input type="hidden" name="action" value="delete_logistics_company"><input type="hidden" name="logistics_id" value="<?=h($lg['id']??'')?>"><button class="danger small">刪除</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>


  <section class="ops-card ops-tab" id="repair-documents">
    <h2>維修單據</h2>
    <p class="muted">維修單號依建立日期自動產生四位隨機碼，並分別記錄處理進度、維修狀況與排除狀況。</p>

    <form method="post" class="product-form">
      <input type="hidden" name="action" value="create_repair_document">
      <label>維修單號<input value="儲存後依建立日期自動產生" readonly></label>
      <label>維修商品<input name="item" placeholder="商品名稱 / 型號 / 序號 / 故障摘要"></label>
      <label>聯絡人<input name="contact_name" placeholder="客戶或送修人"></label>
      <label>聯絡電話<input name="phone" placeholder="電話 / 手機"></label>
      <label class="wide">聯絡地址<input name="address" placeholder="收件或聯絡地址"></label>
      <label>維修人<input name="technician" placeholder="工程師"></label>
      <label>登記人<input name="registrar" value="<?=h(current_operator())?>"></label>
      <label>預估費用<input name="estimated_fee" type="number" min="0" step="1" value="0"></label>
      <label>結算狀態<select name="settlement_status"><?php foreach(['未結算','已結算','免收','待報價','取消'] as $v): ?><option><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>處理進度<select name="repair_status"><?php foreach(['待檢測','維修中','待料','待報價','已完成','已取件','取消'] as $v): ?><option><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>建立時間<input name="created_at" type="datetime-local" value="<?=h(date('Y-m-d\TH:i'))?>"></label>
      <label class="wide">維修狀況<textarea name="repair_condition" rows="2" placeholder="故障現象、檢測結果、目前維修內容"></textarea></label>
      <label class="wide">排除狀況<textarea name="exclusion_condition" rows="2" placeholder="已排除項目、仍未排除問題、後續處理方式"></textarea></label>
      <label class="wide">備註<textarea name="note" rows="2" placeholder="故障原因、客戶交代、報價備註、取件狀態"></textarea></label>
      <button class="primary">新增維修單據</button>
    </form>

    <?php
      $repairQ = trim($_GET['repair_q'] ?? '');
      $repairStatusFilter = trim($_GET['repair_status'] ?? '');
      $repairSettlementFilter = trim($_GET['repair_settlement'] ?? '');
      $repairShown = array_values(array_filter($repairDocuments, function($r) use ($repairQ, $repairStatusFilter, $repairSettlementFilter) {
          $hay = implode(' ', [$r['repair_no'] ?? '', $r['item'] ?? '', $r['contact_name'] ?? '', $r['phone'] ?? '', $r['address'] ?? '', $r['technician'] ?? '', $r['registrar'] ?? '', $r['repair_status'] ?? '', $r['repair_condition'] ?? '', $r['exclusion_condition'] ?? '', $r['settlement_status'] ?? '', $r['note'] ?? '']);
          if ($repairQ !== '' && mb_stripos($hay, $repairQ, 0, 'UTF-8') === false) return false;
          if ($repairStatusFilter !== '' && ($r['repair_status'] ?? '') !== $repairStatusFilter) return false;
          if ($repairSettlementFilter !== '' && ($r['settlement_status'] ?? '') !== $repairSettlementFilter) return false;
          return true;
      }));
      usort($repairShown, function($a, $b) { return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')); });
    ?>
    <form method="get" class="inline-actions">
      <input type="hidden" name="v" value="<?=h($_GET['v'] ?? '')?>">
      <label>搜尋維修單<input name="repair_q" value="<?=h($repairQ)?>" placeholder="單號 / 商品 / 聯絡人 / 電話 / 維修人"></label>
      <label>處理進度<select name="repair_status"><option value="">全部</option><?php foreach(['待檢測','維修中','待料','待報價','已完成','已取件','取消'] as $v): ?><option value="<?=h($v)?>" <?=$repairStatusFilter===$v?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>結算狀態<select name="repair_settlement"><option value="">全部</option><?php foreach(['未結算','已結算','免收','待報價','取消'] as $v): ?><option value="<?=h($v)?>" <?=$repairSettlementFilter===$v?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></label>
      <button class="secondary">查詢</button>
      <a class="secondary-link" href="operations.php#repair-documents">清除</a>
    </form>

    <form id="repairBulkDeleteForm" method="post" onsubmit="return confirm('確定刪除勾選的維修單據？');"><input type="hidden" name="action" value="delete_repair_documents"></form>
    <div class="bulk-bar"><label class="check"><input type="checkbox" data-select-all="repair_ids[]"> 全選維修單據</label><button class="danger-button" type="submit" form="repairBulkDeleteForm">刪除勾選維修單據</button></div>
    <div class="table-wrap"><table><thead><tr><th>選</th><th>單號</th><th>維修商品</th><th>聯絡人</th><th>電話</th><th>地址</th><th>維修人</th><th>登記人</th><th>預估費用</th><th>結算</th><th>處理進度</th><th>維修狀況</th><th>排除狀況</th><th>建立時間</th><th>備註</th><th>操作</th></tr></thead><tbody>
      <?php if (!$repairShown): ?><tr><td colspan="16" class="muted">目前沒有符合條件的維修單據。</td></tr><?php endif; ?>
      <?php foreach($repairShown as $r): ?><tr>
        <td><input type="checkbox" name="repair_ids[]" value="<?=h($r['id'] ?? '')?>" form="repairBulkDeleteForm"></td>
        <form method="post">
          <input type="hidden" name="action" value="save_repair_document">
          <input type="hidden" name="repair_id" value="<?=h($r['id'] ?? '')?>">
          <td><input value="<?=h($r['repair_no'] ?? '')?>" readonly></td>
          <td><input name="item" value="<?=h($r['item'] ?? '')?>"></td>
          <td><input name="contact_name" value="<?=h($r['contact_name'] ?? '')?>"></td>
          <td><input name="phone" value="<?=h($r['phone'] ?? '')?>"></td>
          <td><input name="address" value="<?=h($r['address'] ?? '')?>"></td>
          <td><input name="technician" value="<?=h($r['technician'] ?? '')?>"></td>
          <td><input name="registrar" value="<?=h($r['registrar'] ?? '')?>"></td>
          <td><input name="estimated_fee" type="number" min="0" step="1" value="<?=h($r['estimated_fee'] ?? 0)?>"></td>
          <td><select name="settlement_status"><?php foreach(['未結算','已結算','免收','待報價','取消'] as $v): ?><option <?=$v===($r['settlement_status'] ?? '')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td>
          <td><select name="repair_status"><?php foreach(['待檢測','維修中','待料','待報價','已完成','已取件','取消'] as $v): ?><option <?=$v===($r['repair_status'] ?? '')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td>
          <td><textarea name="repair_condition" rows="2"><?=h($r['repair_condition'] ?? '')?></textarea></td>
          <td><textarea name="exclusion_condition" rows="2"><?=h($r['exclusion_condition'] ?? '')?></textarea></td>
          <td><input name="created_at" value="<?=h($r['created_at'] ?? '')?>"></td>
          <td><textarea name="note" rows="2"><?=h($r['note'] ?? '')?></textarea></td>
          <td><button class="secondary small">儲存</button>
        </form>
        <form method="post" class="inline-form" onsubmit="return confirm('確定刪除此筆維修單據？');">
          <input type="hidden" name="action" value="delete_repair_document">
          <input type="hidden" name="repair_id" value="<?=h($r['id'] ?? '')?>">
          <button class="danger small">刪除</button>
        </form></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
  </section>

<section class="ops-card ops-tab" id="returns">
    <h2>進貨退回 / 銷貨退回 / 故障退回</h2>
    <p class="muted">進貨退回會扣回庫存並進入進退貨統計，單號 <b>BACK-F-日期-流水</b>。客戶銷貨退回單號 <b>BACK-C-日期-流水</b>。</p>
    <div class="colored-section stock-in-section">
      <div class="section-head compact">
        <div>
          <h3>建立進貨退回單</h3>
          <p class="muted">輸入產品編號或條碼後建立退回給廠商的單據，金額會列入進貨退回統計。</p>
        </div>
      </div>
      <form method="post" class="mini-form">
        <input type="hidden" name="action" value="purchase_return">
        <label>退回單號<input name="purchase_return_doc_no" placeholder="留空自動產生 BACK-F-<?=h(date('Ymd'))?>-001"></label>
        <label>退回日期<input name="purchase_return_doc_date" type="date" value="<?=h(date('Y-m-d'))?>"></label>
        <label>廠商名稱<input name="purchase_return_supplier_name" list="supplierNameList" placeholder="可輸入或選擇廠商"></label>
        <datalist id="supplierNameList"><?php foreach($suppliers as $sp): ?><option value="<?=h($sp['name'] ?? '')?>"></option><?php endforeach; ?></datalist>
        <label>經手人<input name="purchase_return_handler" value="<?=h(current_operator())?>"></label>
        <label>產品編號 / 條碼<input name="purchase_return_items[0][product_key]" placeholder="掃描條碼或輸入產品編號"></label>
        <label>退回數量<input name="purchase_return_items[0][qty]" type="number" min="1" value="1"></label>
        <label>單位成本<input name="purchase_return_items[0][unit_cost]" type="number" min="0" step="1" placeholder="留空抓產品成本"></label>
        <label class="wide">退回原因<input name="purchase_return_reason" placeholder="瑕疵、進錯貨、廠商退回、其他"></label>
        <label class="wide">備註<input name="purchase_return_items[0][note]" placeholder="補充說明"></label>
        <button class="primary">建立進貨退回單</button>
      </form>
    </div>
    <hr>
    <form method="post" class="mini-form">
      <input type="hidden" name="action" value="create_return">
      <label>選擇原得標單<select name="schedule_id"><?php foreach($schedules as $s): ?><option value="<?=h($s['id'])?>"><?=h(($s['winner']??'未填得標者').' - '.($s['product_id']??'').' - '.($s['close_at']??''))?></option><?php endforeach; ?></select></label>
      <label>退回數量<input name="return_qty" type="number" min="1" value="1"></label>
      <label>退款金額<input name="refund_amount" type="number" value="0"></label>
      <label>狀態<select name="return_status"><option>申請中</option><option>已收到退貨</option><option>檢測中</option><option>已退款</option><option>換貨處理</option><option>完成</option><option>拒絕退回</option></select></label>
      <label>處理方式<select name="solution"><option>待判斷</option><option>退款</option><option>換貨</option><option>維修</option><option>補寄</option><option>不受理</option></select></label>
      <label>收到日期<input name="received_date" type="date"></label>
      <label class="wide">退回原因<textarea name="reason" rows="2"></textarea></label>
      <label class="wide">商品狀況 / 處理備註<textarea name="condition_note" rows="2"></textarea></label>
      <button class="primary">建立退回紀錄</button>
    </form>
    <form id="returnsBulkDeleteForm" method="post" onsubmit="return confirm('確定刪除勾選的退回紀錄？');"><input type="hidden" name="action" value="delete_returns"></form><div class="bulk-bar"><label class="check"><input type="checkbox" data-select-all="return_ids[]"> 全選退回紀錄</label><button class="danger-button" type="submit" form="returnsBulkDeleteForm">刪除勾選退回紀錄</button></div><div class="table-wrap"><table class="return-table"><thead><tr><th>選</th><th>單號</th><th>狀態</th><th>會員</th><th>商品</th><th>數量</th><th>退款</th><th>原因</th><th>處理方式</th><th>收到</th><th>完成</th><th>更新</th></tr></thead><tbody>
      <?php foreach($returns as $r): ?><tr><td><input type="checkbox" name="return_ids[]" value="<?=h($r['id']??'')?>" form="returnsBulkDeleteForm"></td><form method="post"><input type="hidden" name="action" value="save_return"><input type="hidden" name="return_id" value="<?=h($r['id'])?>"><td><b><?=h($r['return_no'] ?? '')?></b></td><td><select name="status"><?php foreach(['申請中','已收到退貨','檢測中','已退款','換貨處理','完成','拒絕退回'] as $v): ?><option <?=($r['status']??'')===$v?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td><td><?=h(($r['member_name']??'').' / '.($r['member_facebook']??'').' / '.($r['member_phone']??''))?></td><td><?=h($r['product_id']??'')?></td><td><input name="return_qty" type="number" value="<?=h($r['return_qty']??1)?>"></td><td><input name="refund_amount" type="number" value="<?=h($r['refund_amount']??0)?>"></td><td><input name="reason" value="<?=h($r['reason']??'')?>"></td><td><select name="solution"><?php foreach(['待判斷','退款','換貨','維修','補寄','不受理'] as $v): ?><option <?=($r['solution']??'')===$v?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td><td><input name="received_date" type="date" value="<?=h($r['received_date']??'')?>"></td><td><input name="handled_date" type="date" value="<?=h($r['handled_date']??'')?>"></td><td><button class="secondary">儲存</button></td></form></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>



  <section class="ops-card ops-tab" id="inventory-count">
    <h2>盤點系統 / 盤點單據</h2>
    <p class="muted">掃描條碼後會立即顯示系統庫存、實盤數量與差異。差異只會送出提醒，必須由管理者確認後才會調整庫存。</p>
    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_inventory_count">
      <label>盤點日期<input type="date" name="count_date" value="<?=h(date('Y-m-d'))?>"></label>
      <label>狀態<input value="系統依差異自動判斷" readonly></label>
      <label class="wide">盤點倉庫<select id="inventoryCountWarehouse" name="count_warehouse" required><option value="">請選擇本次要盤點的倉庫</option><?php foreach($warehouseOptions as $w): ?><option value="<?=h($w)?>"><?=h($w)?></option><?php endforeach; ?></select></label>
      <label class="inventory-scan-panel">
        <span>即時掃描條碼</span>
        <input id="inventoryCountScanner" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="掃描條碼後按 Enter">
        <span class="inventory-scan-rules">
          <span class="inventory-scan-rule ok">有庫存：嗶 1 聲</span>
          <span class="inventory-scan-rule zero">零庫存：嗶 2 聲</span>
          <span class="inventory-scan-rule missing">無條碼：嗶 3 聲</span>
        </span>
        <span id="inventoryCountScanStatus" class="inventory-scan-status" role="status" aria-live="polite">等待掃描條碼</span>
      </label>
      <input type="hidden" id="inventoryCountLines" name="count_lines">
      <div class="wide inventory-count-lines-panel">
        <div class="section-head compact">
          <div>
            <h3>已掃描盤點明細</h3>
            <p class="muted">掃描後會自動加入表格；同一條碼重複掃描會累加數量並更新最後盤點時間。</p>
          </div>
        </div>
        <div class="table-wrap">
          <table class="inventory-count-lines-table">
            <thead><tr><th>圖片</th><th>產品 / 條碼</th><th>顏色 / 尺寸 / 規格</th><th>系統庫存</th><th>實盤數量</th><th>差異</th><th>盤點時間</th><th>狀態</th><th>操作</th></tr></thead>
            <tbody id="inventoryCountLineRows">
              <tr class="inventory-count-empty"><td colspan="9" class="muted">尚未掃描盤點明細。</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <label class="wide">備註<input name="count_note" placeholder="盤點人員、區域、異常說明"></label>
      <button>建立盤點單</button>
    </form>
    <div class="table-wrap"><table><thead><tr><th>盤點單號</th><th>日期</th><th>盤點倉庫</th><th>狀態</th><th>筆數</th><th>異常</th><th>建立人</th><th>備註</th><th>管理者處理</th></tr></thead><tbody>
      <?php if(!$inventoryCounts): ?><tr><td colspan="9" class="muted">尚未建立盤點單。</td></tr><?php endif; ?>
      <?php foreach(array_reverse($inventoryCounts) as $doc): $diffs = array_filter($doc['lines'] ?? [], function($l){ return (int)($l['diff_qty'] ?? 0) !== 0; }); ?>
      <tr>
        <td><?=h($doc['doc_no'] ?? '')?></td><td><?=h($doc['date'] ?? '')?></td><td><?=h($doc['warehouse_name'] ?? '')?></td><td><?=h($doc['status'] ?? '')?></td>
        <td><?=h(count($doc['lines'] ?? []))?></td><td><?=h(count($diffs))?></td><td><?=h($doc['operator'] ?? '')?></td><td><?=h($doc['note'] ?? '')?></td>
        <td>
          <?php if($diffs && !in_array(($doc['status'] ?? ''), ['已確認並調整', '退回重盤'], true)): ?>
            <?php if(baohui_can_approve_inventory_count()): ?>
              <div class="inventory-approval-panel">
                <form method="post" onsubmit="return confirm('確定依此盤點差異調整正式庫存？系統將自動產生報損或報溢紀錄。')">
                  <input type="hidden" name="action" value="approve_inventory_count"><input type="hidden" name="count_id" value="<?=h($doc['id'] ?? '')?>">
                  <label>確認說明<input name="approval_note" placeholder="可填寫差異原因"></label>
                  <button>確認差異並調整庫存</button>
                </form>
                <form method="post" onsubmit="return confirm('確定退回重新盤點？庫存不會變更。')">
                  <input type="hidden" name="action" value="return_inventory_count"><input type="hidden" name="count_id" value="<?=h($doc['id'] ?? '')?>">
                  <input type="hidden" name="approval_note" value="請重新盤點確認差異"><button class="secondary">退回重盤</button>
                </form>
              </div>
            <?php else: ?><span class="status-pill">等待管理者確認</span><?php endif; ?>
          <?php elseif(($doc['status'] ?? '') === '已確認並調整'): ?>
            <span class="status-pill">已由 <?=h($doc['approved_by'] ?? '管理者')?> 確認</span><br><small class="muted"><?=h($doc['approved_at'] ?? '')?></small>
          <?php elseif(($doc['status'] ?? '') === '退回重盤'): ?>
            <span class="status-pill">已退回重盤</span>
          <?php else: ?><span class="status-pill">庫存一致</span><?php endif; ?>
        </td>
      </tr>
      <?php if(!empty($doc['lines'])): ?>
      <tr class="inventory-count-detail-row"><td colspan="9">
        <details>
          <summary>查看盤點明細表格</summary>
          <div class="table-wrap">
            <table class="inventory-count-lines-table"><thead><tr><th>圖片</th><th>產品 / 條碼</th><th>顏色 / 尺寸 / 規格</th><th>實盤數量</th><th>系統數量</th><th>差異</th><th>盤點時間</th></tr></thead><tbody>
              <?php foreach($doc['lines'] as $line): $lineProduct=product_by_key($products, ($line['product_id'] ?? '') ?: (($line['barcode'] ?? '') ?: ($line['scan_code'] ?? ''))); $lineImgs=product_images($lineProduct ?: []); $lineSpec=trim(implode(' / ', array_filter([$line['color']??'', $line['size']??'', $line['spec']??''], function($v){ return trim((string)$v) !== ''; }))); ?>
                <tr>
                  <td><?php if(!empty($lineImgs[0])): ?><img class="thumb zoomable" src="<?=h($lineImgs[0])?>"><?php else: ?><span class="muted">無圖</span><?php endif; ?></td>
                  <td><b><?=h($line['title'] ?? '')?></b><br><span class="muted"><?=h(($line['barcode'] ?? '') ?: ($line['scan_code'] ?? ''))?></span></td>
                  <td><?=h($lineSpec ?: '-')?></td>
                  <td><?=h($line['qty'] ?? 0)?></td>
                  <td><?=h($line['system_qty'] ?? 0)?></td>
                  <?php $lineDiff=(int)($line['diff_qty'] ?? 0); ?><td class="inventory-diff <?=$lineDiff === 0 ? 'is-match' : ($lineDiff < 0 ? 'is-short' : 'is-over')?>"><?=h(($lineDiff > 0 ? '+' : '') . $lineDiff)?></td>
                  <td><?=h($line['checked_at'] ?? '')?></td>
                </tr>
              <?php endforeach; ?>
            </tbody></table>
          </div>
        </details>
      </td></tr>
      <?php endif; ?>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>

  <section class="ops-card ops-tab" id="inventory-transfer">
    <h2>庫存調撥 / 調撥單據</h2>
    <p class="muted">調撥單號依伺服器當天日期統一自動流水，例如 TRF-<?=h(date('Ymd'))?>-001；日期與單號皆不可手動修改。</p>
    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_inventory_transfer">
      <label>調撥單號<input value="TRF-<?=h(date('Ymd'))?>-儲存後自動流水" readonly></label>
      <label>調撥日期<input type="date" value="<?=h(date('Y-m-d'))?>" readonly></label>
      <label>狀態<select name="transfer_status"><option>待調撥</option><option>調撥中</option><option>已完成</option><option>取消</option></select></label>
      <label>來源倉庫<select name="from_warehouse"><option value="">請選擇來源倉庫</option><?php foreach($warehouseOptions as $w): ?><option value="<?=h($w)?>"><?=h($w)?></option><?php endforeach; ?></select></label>
      <label>來源貨架<select name="from_shelf"><option value="">請選擇來源貨架</option><?php foreach($shelfOptions as $shelf): ?><option value="<?=h($shelf)?>"><?=h($shelf)?></option><?php endforeach; ?></select></label>
      <label>來源倉位<select name="from_location"><option value="">請選擇來源倉位</option><?php foreach($layerOptions as $layer): ?><option value="<?=h($layer)?>"><?=h($layer)?></option><?php endforeach; ?></select></label>
      <label>目的倉庫<select name="to_warehouse"><option value="">請選擇目的倉庫</option><?php foreach($warehouseOptions as $w): ?><option value="<?=h($w)?>"><?=h($w)?></option><?php endforeach; ?></select></label>
      <label>目的貨架<select name="to_shelf"><option value="">請選擇目的貨架</option><?php foreach($shelfOptions as $shelf): ?><option value="<?=h($shelf)?>"><?=h($shelf)?></option><?php endforeach; ?></select></label>
      <label>目的倉位<select name="to_location"><option value="">請選擇目的倉位</option><?php foreach($layerOptions as $layer): ?><option value="<?=h($layer)?>"><?=h($layer)?></option><?php endforeach; ?></select></label>
      <label class="wide">條碼多筆輸入<textarea name="transfer_lines" rows="6" placeholder="例如：SY41P140904 1&#10;A02-01 2"></textarea></label>
      <label class="wide">備註<input name="transfer_note" placeholder="調撥原因、經手人、目的用途"></label>
      <button>建立調撥單</button>
    </form>
    <div class="table-wrap"><table><thead><tr><th>調撥單號</th><th>日期</th><th>狀態</th><th>來源</th><th>目的</th><th>筆數</th><th>建立人</th><th>備註</th></tr></thead><tbody>
      <?php if(!$inventoryTransfers): ?><tr><td colspan="8" class="muted">尚未建立調撥單。</td></tr><?php endif; ?>
      <?php foreach(array_reverse($inventoryTransfers) as $doc): ?>
      <tr>
        <td><?=h($doc['doc_no'] ?? '')?></td><td><?=h($doc['date'] ?? '')?></td><td><?=h($doc['status'] ?? '')?></td>
        <td><?=h(trim(($doc['from_warehouse']??'').' / '.($doc['from_shelf']??'').' / '.($doc['from_location']??''), ' /'))?></td>
        <td><?=h(trim(($doc['to_warehouse']??'').' / '.($doc['to_shelf']??'').' / '.($doc['to_location']??''), ' /'))?></td>
        <td><?=h(count($doc['lines'] ?? []))?></td><td><?=h($doc['operator'] ?? '')?></td><td><?=h($doc['note'] ?? '')?></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>

  <section class="ops-card ops-tab" id="finance-reconcile">
    <?php
      $reconcileFrom = trim((string)($_GET['reconcile_from'] ?? ''));
      $reconcileTo = trim((string)($_GET['reconcile_to'] ?? ''));
      $reconcileQ = trim((string)($_GET['reconcile_q'] ?? ''));
      $reconcileCustomer = trim((string)($_GET['reconcile_customer'] ?? ''));
      $reconcileStatusFilter = trim((string)($_GET['reconcile_status'] ?? ''));

      $deliveryBySchedule = [];
      foreach ($deliveryNotes as $delivery) {
          foreach (($delivery['schedule_ids'] ?? []) as $scheduleId) {
              $deliveryBySchedule[(string)$scheduleId] = $delivery['delivery_no'] ?? '';
          }
          foreach (($delivery['items'] ?? []) as $item) {
              $scheduleId = trim((string)($item['schedule_id'] ?? ''));
              if ($scheduleId !== '') $deliveryBySchedule[$scheduleId] = $delivery['delivery_no'] ?? '';
          }
      }

      $receiptIndex = [];
      $receiptRowsById = [];
      $badDebtBySchedule = [];
      foreach ($badDebts as $case) {
          if (in_array(($case['status'] ?? ''), ['已追回','已核准沖銷','取消'], true)) continue;
          foreach (($case['items'] ?? []) as $item) {
              $scheduleId = trim((string)($item['schedule_id'] ?? ''));
              if ($scheduleId !== '') $badDebtBySchedule[$scheduleId] = $case['case_no'] ?? '';
          }
      }
      foreach ($collectionReceipts as $receipt) {
          $receiptId = trim((string)($receipt['id'] ?? ''));
          if ($receiptId === '') continue;
          $receiptRowsById[$receiptId] = $receipt;
          $receiptDocumentNos = $receipt['document_nos'] ?? [];
          if (!is_array($receiptDocumentNos) || !$receiptDocumentNos) $receiptDocumentNos = preg_split('/\s*[,、]\s*/u', trim((string)($receipt['document_no'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
          foreach (($receipt['allocations'] ?? []) as $allocation) {
              $receiptDocumentNos[] = $allocation['schedule_id'] ?? '';
              $receiptDocumentNos[] = $allocation['document_no'] ?? '';
          }
          foreach (array_unique(array_filter(array_map('trim', $receiptDocumentNos))) as $documentNo) {
              $documentKey = mb_strtolower($documentNo, 'UTF-8');
              $receiptIndex[$documentKey][] = $receiptId;
          }
      }

      $allReconcileRows = [];
      $linkedReceiptIds = [];
      $customerOptions = [];
      foreach ($schedules as $schedule) {
          $product = product_by_id($products, $schedule['product_id'] ?? '');
          $totalsRow = totals($schedule, $product);
          $scheduleId = trim((string)($schedule['id'] ?? ''));
          $deliveryNo = trim((string)($deliveryBySchedule[$scheduleId] ?? ''));
          $orderNo = trim((string)($schedule['order_no'] ?? $scheduleId));
          $customerName = trim((string)($schedule['winner'] ?? ''));
          if ($customerName === '') $customerName = trim((string)($schedule['winner_facebook'] ?? ''));
          if ($customerName === '') $customerName = '未指定客戶';
          $customerOptions[$customerName] = true;
          $orderDate = substr((string)($schedule['close_at'] ?? $schedule['publish_at'] ?? $schedule['created_at'] ?? ''), 0, 10);
          $isCancelled = in_array((string)($schedule['order_status'] ?? $schedule['status'] ?? ''), ['取消','已取消','作廢'], true);
          $receivable = $isCancelled ? 0 : (float)$totalsRow['receivable'];
          $schedulePaid = $isCancelled ? 0 : (float)($schedule['paid_amount'] ?? 0);

          $documentCandidates = array_values(array_unique(array_filter([
              $scheduleId,
              $orderNo,
              trim((string)($schedule['invoice_no'] ?? '')),
              trim((string)($schedule['product_serial'] ?? '')),
              $deliveryNo,
          ], function($value) { return trim((string)$value) !== ''; })));
          $scheduleReceiptIds = [];
          foreach ($documentCandidates as $candidate) {
              $candidateKey = mb_strtolower(trim((string)$candidate), 'UTF-8');
              foreach (($receiptIndex[$candidateKey] ?? []) as $receiptId) $scheduleReceiptIds[$receiptId] = true;
          }
          $confirmedReceiptAmount = 0;
          $pendingReceiptAmount = 0;
          $receiptNos = [];
          foreach (array_keys($scheduleReceiptIds) as $receiptId) {
              $receipt = $receiptRowsById[$receiptId] ?? [];
              $receiptStatus = trim((string)($receipt['status'] ?? '待確認'));
              if ($receiptStatus === '作廢') continue;
              $linkedReceiptIds[$receiptId] = true;
              $receiptAmount = 0;
              $receiptAllocations = $receipt['allocations'] ?? [];
              if (is_array($receiptAllocations) && $receiptAllocations) {
                  foreach ($receiptAllocations as $allocation) {
                      if (($allocation['schedule_id'] ?? '') === $scheduleId || ($allocation['document_no'] ?? '') === $orderNo) $receiptAmount += max(0, (float)($allocation['amount'] ?? 0));
                  }
                  if ($receiptAmount <= 0) continue;
              } else {
                  $receiptAmount = max(0, (float)($receipt['amount'] ?? 0));
              }
              if (in_array($receiptStatus, ['已確認','部分收款'], true)) $confirmedReceiptAmount += $receiptAmount;
              else $pendingReceiptAmount += $receiptAmount;
              if (trim((string)($receipt['receipt_no'] ?? '')) !== '') $receiptNos[] = $receipt['receipt_no'];
          }

          $paid = $isCancelled ? 0 : max($schedulePaid, $confirmedReceiptAmount);
          $outstanding = max(0, $receivable - $paid);
          $overpaid = max(0, $paid - $receivable);
          if ($isCancelled) $reconcileStatus = '已取消';
          elseif ($outstanding > 0 && isset($badDebtBySchedule[$scheduleId])) $reconcileStatus = '呆帳追蹤';
          elseif ($overpaid > 0) $reconcileStatus = '溢收';
          elseif ($outstanding <= 0 && $receivable > 0) $reconcileStatus = '已結清';
          elseif ($paid > 0) $reconcileStatus = '部分收款';
          else $reconcileStatus = '未收款';
          $paymentMethods = [];
          foreach (($schedule['payment_history'] ?? []) as $history) {
              $method = trim((string)($history['method'] ?? ''));
              if ($method !== '') $paymentMethods[$method] = true;
          }
          $sources = [];
          if ($schedulePaid > 0) $sources[] = '得標結算沖帳';
          if ($confirmedReceiptAmount > 0) $sources[] = '已確認收款單';
          if ($pendingReceiptAmount > 0) $sources[] = '待確認收款 ' . money($pendingReceiptAmount);
          if (isset($badDebtBySchedule[$scheduleId])) $sources[] = '呆帳 ' . $badDebtBySchedule[$scheduleId];
          if (!$sources) $sources[] = '尚無收款紀錄';
          $allReconcileRows[] = [
              'date' => $orderDate,
              'schedule_id' => $scheduleId,
              'document_no' => $orderNo,
              'delivery_no' => $deliveryNo,
              'invoice_no' => trim((string)($schedule['invoice_no'] ?? '')),
              'customer' => $customerName,
              'phone' => trim((string)($schedule['winner_phone'] ?? '')),
              'product' => trim((string)($schedule['product_title'] ?? ($product['title'] ?? ''))),
              'quantity' => max(1, (int)($schedule['quantity'] ?? 1)),
              'receivable' => $receivable,
              'paid' => $paid,
              'outstanding' => $outstanding,
              'overpaid' => $overpaid,
              'status' => $reconcileStatus,
              'payment_source' => implode('、', $sources),
              'payment_method' => implode('、', array_keys($paymentMethods)),
              'receipt_nos' => implode('、', array_unique($receiptNos)),
              'shipping_status' => trim((string)($schedule['shipping_status'] ?? '')),
              'note' => trim((string)($schedule['settlement_note'] ?? '')),
          ];
      }
      ksort($customerOptions, SORT_NATURAL);

      $reconcileRows = array_values(array_filter($allReconcileRows, function($row) use ($reconcileFrom, $reconcileTo, $reconcileQ, $reconcileCustomer, $reconcileStatusFilter) {
          if ($reconcileFrom !== '' && $row['date'] !== '' && $row['date'] < $reconcileFrom) return false;
          if ($reconcileTo !== '' && $row['date'] !== '' && $row['date'] > $reconcileTo) return false;
          if ($reconcileCustomer !== '' && $row['customer'] !== $reconcileCustomer) return false;
          if ($reconcileStatusFilter !== '' && $row['status'] !== $reconcileStatusFilter) return false;
          if ($reconcileQ !== '') {
              $haystack = implode(' ', [$row['document_no'], $row['delivery_no'], $row['invoice_no'], $row['customer'], $row['phone'], $row['product'], $row['receipt_nos']]);
              if (mb_stripos($haystack, $reconcileQ, 0, 'UTF-8') === false) return false;
          }
          return true;
      }));
      usort($reconcileRows, function($a, $b) { return strcmp(($b['date'] ?? '') . ($b['document_no'] ?? ''), ($a['date'] ?? '') . ($a['document_no'] ?? '')); });

      $reconcileReceivable = array_sum(array_column($reconcileRows, 'receivable'));
      $reconcilePaid = array_sum(array_column($reconcileRows, 'paid'));
      $reconcileOutstanding = array_sum(array_column($reconcileRows, 'outstanding'));
      $reconcileOverpaid = array_sum(array_column($reconcileRows, 'overpaid'));
      $reconcileCustomerSummary = [];
      foreach ($reconcileRows as $row) {
          $key = $row['customer'];
          if (!isset($reconcileCustomerSummary[$key])) $reconcileCustomerSummary[$key] = ['customer'=>$key,'orders'=>0,'receivable'=>0,'paid'=>0,'outstanding'=>0,'overpaid'=>0];
          $reconcileCustomerSummary[$key]['orders']++;
          foreach (['receivable','paid','outstanding','overpaid'] as $field) $reconcileCustomerSummary[$key][$field] += $row[$field];
      }
      uasort($reconcileCustomerSummary, function($a, $b) { return $b['outstanding'] <=> $a['outstanding']; });

      $unallocatedReceipts = [];
      $unallocatedReceiptTotal = 0;
      foreach ($collectionReceipts as $receipt) {
          $receiptId = trim((string)($receipt['id'] ?? ''));
          $receiptDate = substr((string)($receipt['receipt_date'] ?? $receipt['created_at'] ?? ''), 0, 10);
          if (isset($linkedReceiptIds[$receiptId]) || ($receipt['status'] ?? '') === '作廢') continue;
          if ($reconcileFrom !== '' && $receiptDate !== '' && $receiptDate < $reconcileFrom) continue;
          if ($reconcileTo !== '' && $receiptDate !== '' && $receiptDate > $reconcileTo) continue;
          $unallocatedReceipts[] = $receipt;
          if (in_array(($receipt['status'] ?? ''), ['已確認','部分收款'], true)) $unallocatedReceiptTotal += max(0, (float)($receipt['amount'] ?? 0));
      }
    ?>
    <div class="reconcile-head">
      <div>
        <h2>財務系統 / 銷售收款對帳單</h2>
        <p class="muted">即時連結得標結算、銷售訂單、出貨單、付款沖帳與收款單。已確認收款才列入已收金額，待確認款項會另外標示。</p>
      </div>
      <div class="reconcile-actions">
        <button type="button" class="secondary" id="printReconcileStatement">列印橫式 A4</button>
        <button type="button" class="secondary" id="exportReconcileCsv">匯出 CSV</button>
        <a class="button-like" href="#finance-collection" data-jump-tab="finance-collection">新增收款單</a>
      </div>
    </div>
    <div class="reconcile-print-title">
      <h2><?=h($companyProfile['company_name'] ?? '寶輝科技有限公司')?>｜銷售收款對帳單</h2>
      <div>查詢期間：<?=h($reconcileFrom ?: '不限')?> 至 <?=h($reconcileTo ?: '不限')?>｜列印日期：<?=h(date('Y-m-d'))?></div>
    </div>
    <form method="get" action="operations.php#finance-reconcile" class="product-form reconcile-filter">
      <input type="hidden" name="reconcile_open" value="1">
      <label>訂單起日<input type="date" name="reconcile_from" value="<?=h($reconcileFrom)?>"></label>
      <label>訂單迄日<input type="date" name="reconcile_to" value="<?=h($reconcileTo)?>"></label>
      <label>客戶<select name="reconcile_customer"><option value="">全部客戶</option><?php foreach(array_keys($customerOptions) as $customer): ?><option value="<?=h($customer)?>" <?=$reconcileCustomer===$customer?'selected':''?>><?=h($customer)?></option><?php endforeach; ?></select></label>
      <label>對帳狀態<select name="reconcile_status"><option value="">全部狀態</option><?php foreach(['未收款','部分收款','呆帳追蹤','已結清','溢收','已取消'] as $status): ?><option value="<?=h($status)?>" <?=$reconcileStatusFilter===$status?'selected':''?>><?=h($status)?></option><?php endforeach; ?></select></label>
      <label class="wide">單號 / 客戶 / 電話 / 商品 / 收款單<input name="reconcile_q" value="<?=h($reconcileQ)?>" placeholder="輸入關鍵字查詢"></label>
      <div class="form-actions wide"><button class="primary">查詢對帳</button><a class="button-like" href="operations.php#finance-reconcile">清除條件</a></div>
    </form>
    <div class="metric-grid">
      <div class="metric"><span>應收總額</span><strong><?=money($reconcileReceivable)?></strong><small><?=h(count($reconcileRows))?> 筆訂單</small></div>
      <div class="metric"><span>已收總額</span><strong><?=money($reconcilePaid)?></strong><small>結算沖帳與已確認收款單</small></div>
      <div class="metric"><span>未收總額</span><strong class="danger-text"><?=money($reconcileOutstanding)?></strong><small>仍需追蹤</small></div>
      <div class="metric"><span>溢收金額</span><strong><?=money($reconcileOverpaid)?></strong><small>需退款或保留餘額</small></div>
      <div class="metric"><span>未分配收款</span><strong><?=money($unallocatedReceiptTotal)?></strong><small><?=h(count($unallocatedReceipts))?> 筆尚未對應訂單</small></div>
    </div>

    <h3 class="reconcile-section-title">客戶對帳彙總</h3>
    <div class="table-wrap"><table class="reconcile-summary-table"><thead><tr><th>客戶</th><th>訂單筆數</th><th>應收</th><th>已收</th><th>未收</th><th>溢收</th><th>狀態</th></tr></thead><tbody>
      <?php foreach($reconcileCustomerSummary as $summary): ?><tr>
        <td class="reconcile-customer"><?=h($summary['customer'])?></td><td><?=h($summary['orders'])?></td><td><?=money($summary['receivable'])?></td><td><?=money($summary['paid'])?></td><td class="<?=($summary['outstanding']>0?'danger-text':'')?>"><?=money($summary['outstanding'])?></td><td><?=money($summary['overpaid'])?></td>
        <td><span class="reconcile-status <?=($summary['outstanding']>0?'unpaid':'paid')?>"><?=$summary['outstanding']>0?'尚有未收款':'已結清'?></span></td>
      </tr><?php endforeach; ?>
      <?php if(!$reconcileCustomerSummary): ?><tr><td colspan="7" class="muted">目前條件下沒有可對帳的銷售資料。</td></tr><?php endif; ?>
    </tbody></table></div>

    <h3 class="reconcile-section-title">訂單與收款明細</h3>
    <div class="table-wrap"><table class="reconcile-detail-table" id="reconcileDetailTable"><thead><tr><th>訂單日期</th><th>來源單號</th><th>出貨單 / 發票</th><th>客戶</th><th>商品</th><th>數量</th><th>應收</th><th>已收</th><th>未收</th><th>溢收</th><th>狀態</th><th>收款來源</th><th>物流狀態</th><th>備註</th><th>操作</th></tr></thead><tbody>
      <?php foreach($reconcileRows as $row): $statusClass=$row['status']==='已結清'?'paid':($row['status']==='部分收款'?'partial':($row['status']==='溢收'?'over':'unpaid')); ?><tr>
        <td><?=h($row['date'])?></td><td><b><?=h($row['document_no'])?></b><div class="reconcile-source"><?=h($row['receipt_nos'])?></div></td><td><?=h(trim($row['delivery_no'].' / '.$row['invoice_no'], ' /'))?></td><td><span class="reconcile-customer"><?=h($row['customer'])?></span><div class="reconcile-source"><?=h($row['phone'])?></div></td><td><?=h($row['product'])?></td><td><?=h($row['quantity'])?></td><td><?=money($row['receivable'])?></td><td><?=money($row['paid'])?></td><td class="<?=($row['outstanding']>0?'danger-text':'')?>"><?=money($row['outstanding'])?></td><td><?=money($row['overpaid'])?></td><td><span class="reconcile-status <?=h($statusClass)?>"><?=h($row['status'])?></span></td><td><?=h($row['payment_source'])?><div class="reconcile-source"><?=h($row['payment_method'])?></div></td><td><?=h($row['shipping_status'])?></td><td><?=h($row['note'])?></td><td><?php if($row['schedule_id']!==''): ?><button type="button" class="button-like small edit-settlement" data-id="<?=h($row['schedule_id'])?>">開啟結算</button><?php endif; ?></td>
      </tr><?php endforeach; ?>
      <?php if(!$reconcileRows): ?><tr><td colspan="15" class="muted">目前條件下沒有可對帳的訂單；新產生的得標結算與收款會自動顯示在這裡。</td></tr><?php endif; ?>
    </tbody></table></div>

    <h3 class="reconcile-section-title">未分配或無法連結的收款單</h3>
    <p class="muted">這些收款單尚未填入正確的對應單號，或對應單號找不到訂單。請到收款單修正後，系統會自動完成連結。</p>
    <div class="table-wrap"><table><thead><tr><th>收款日期</th><th>收款單號</th><th>客戶</th><th>對應單號</th><th>金額</th><th>狀態</th><th>方式</th><th>備註</th></tr></thead><tbody>
      <?php foreach($unallocatedReceipts as $receipt): ?><tr><td><?=h($receipt['receipt_date'] ?? '')?></td><td><?=h($receipt['receipt_no'] ?? '')?></td><td><?=h($receipt['customer_name'] ?? '')?></td><td><?=h($receipt['document_no'] ?? '')?></td><td><?=money($receipt['amount'] ?? 0)?></td><td><?=h($receipt['status'] ?? '')?></td><td><?=h($receipt['payment_method'] ?? '')?></td><td><?=h($receipt['note'] ?? '')?></td></tr><?php endforeach; ?>
      <?php if(!$unallocatedReceipts): ?><tr><td colspan="8" class="muted">目前沒有未分配收款。</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>


  <section class="ops-card ops-tab" id="finance-analytics">
    <h2>財務系統 / 數據分析</h2>
    <p class="muted">統計來源：正式進貨入庫、銷售出庫、已打的進貨退回／銷貨退回與一元競標結算。管家婆收付款帳戶明細不列入進銷退。今日、近 7 天、本月會分開顯示。</p>
    <div class="metric-grid">
      <?php foreach($financeAnalytics as $an): ?>
        <div class="metric">
          <span><?=h($an['label'])?>營業額</span>
          <strong><?=money($an['sales_revenue'])?></strong>
          <small>毛利 <?=money($an['gross_profit'])?>｜虧損 <?=money($an['loss_amount'])?></small>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="analytics-chart-grid">
      <?php foreach($financeAnalytics as $an): ?>
        <div class="analytics-chart-card">
          <h3><?=h($an['label'])?>圖表</h3>
          <?php
            $chartRows = [
              ['營業額', (float)$an['sales_revenue'], ''],
              ['進貨成本', (float)$an['stock_in_cost'], 'cost'],
              ['進貨退回', (float)$an['purchase_return_amount'], 'profit'],
              ['銷售成本', (float)$an['sales_cost'], 'cost'],
              ['銷貨退回', (float)$an['sales_return_amount'], 'loss'],
              ['毛利', (float)$an['gross_profit'], 'profit'],
              ['虧損', (float)$an['loss_amount'], 'loss'],
            ];
          ?>
          <?php foreach($chartRows as $cr): $width = max(2, min(100, abs($cr[1]) / $financeChartMax * 100)); ?>
            <div class="chart-row">
              <span><?=h($cr[0])?></span>
              <div class="chart-track"><div class="chart-bar <?=h($cr[2])?>" style="width:<?=h(round($width, 1))?>%"></div></div>
              <span class="chart-value <?=($cr[1] < 0 ? 'danger-text' : '')?>"><?=money($cr[1])?></span>
            </div>
          <?php endforeach; ?>
          <p class="muted">進貨 <?=h($an['stock_in_qty'])?> 件｜進貨退回 <?=h($an['purchase_return_qty'])?> 件｜銷售 <?=h($an['sales_qty'])?> 件｜銷貨退回 <?=h($an['sales_return_qty'])?> 件｜銷售/得標 <?=h($an['order_count'])?> 筆</p>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>期間</th><th>日期範圍</th><th>進貨數量</th><th>進貨成本總和</th><th>進貨退回</th><th>銷售筆數</th><th>銷售數量</th><th>銷貨退回</th><th>營業額</th><th>銷售成本</th><th>毛利 / 虧損</th><th>虧損總額</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($financeAnalytics as $an): ?>
            <tr>
              <td><b><?=h($an['label'])?></b></td>
              <td><?=h($an['start'])?> 至 <?=h($an['end'])?></td>
              <td><?=h($an['stock_in_qty'])?></td>
              <td><?=money($an['stock_in_cost'])?></td>
              <td><?=h($an['purchase_return_qty'])?> 件 / <?=money($an['purchase_return_amount'])?></td>
              <td><?=h($an['order_count'])?></td>
              <td><?=h($an['sales_qty'])?></td>
              <td><?=h($an['sales_return_qty'])?> 件 / <?=money($an['sales_return_amount'])?></td>
              <td><?=money($an['sales_revenue'])?></td>
              <td><?=money($an['sales_cost'])?></td>
              <td class="<?=($an['gross_profit'] < 0 ? 'danger-text' : '')?>"><?=money($an['gross_profit'])?></td>
              <td class="<?=($an['loss_amount'] > 0 ? 'danger-text' : '')?>"><?=money($an['loss_amount'])?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <h3>本月虧損產品清單</h3>
    <p class="muted">只列出本月毛利小於 0 的得標品項，方便追成本、低價結標、退貨或其他異常。</p>
    <div class="table-wrap">
      <table>
        <thead><tr><th>日期</th><th>產品編號</th><th>條碼</th><th>產品</th><th>數量</th><th>營業額</th><th>成本</th><th>虧損</th><th>得標者</th></tr></thead>
        <tbody>
          <?php foreach($financeLossRows as $loss): ?>
            <tr>
              <td><?=h($loss['date'])?></td>
              <td><?=h($loss['product_id'])?></td>
              <td><?=h($loss['barcode'])?></td>
              <td><?=h($loss['title'])?></td>
              <td><?=h($loss['qty'])?></td>
              <td><?=money($loss['revenue'])?></td>
              <td><?=money($loss['cost'])?></td>
              <td class="danger-text"><?=money($loss['profit'])?></td>
              <td><?=h($loss['winner'])?></td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$financeLossRows): ?><tr><td colspan="9" class="muted">本月目前沒有虧損產品。</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="ops-card ops-tab" id="finance-report">
    <h2>財務系統 / 公司財報</h2>
    <p class="muted">本月營收、銷售成本與毛利讀取得標結算資料；固定開支以實際標記「已付款」且付款日在本月的紀錄計入。</p>
    <div class="metric-grid">
      <div class="metric"><span>本月營業額</span><strong><?=money($financeReportMonthRevenue)?></strong></div>
      <div class="metric"><span>本月固定開支已付</span><strong><?=money($fixedExpenseCashPaidThisMonth)?></strong><small>待付 <?=money($fixedExpenseMonthOutstanding)?></small></div>
      <div class="metric"><span>本月毛利</span><strong><?=money($financeReportMonthGrossProfit)?></strong></div>
      <div class="metric"><span>毛利扣固定開支</span><strong class="<?=$financeReportMonthNetAfterFixed < 0 ? 'danger-text' : ''?>"><?=money($financeReportMonthNetAfterFixed)?></strong></div>
      <div class="metric"><span>固定資產帳面價值</span><strong><?=money($fixedAssetBookValue)?></strong><small><?=h($fixedAssetActiveCount)?> 筆使用中 / 管理中</small></div>
      <div class="metric"><span>本月固定資產折舊</span><strong><?=money($fixedAssetMonthDepreciation)?></strong><small>非現金費用</small></div>
    </div>
    <div class="table-wrap"><table><thead><tr><th>期間</th><th>營業收入</th><th>銷售成本</th><th>營業毛利</th><th>固定開支已付</th><th>固定開支待付</th><th>本月折舊</th><th>扣固定開支與折舊後</th><th>備註</th></tr></thead><tbody><tr><td><?=h($fixedExpenseCurrentPeriod)?></td><td><?=money($financeReportMonthRevenue)?></td><td><?=money($financeReportMonthSalesCost)?></td><td><?=money($financeReportMonthGrossProfit)?></td><td><?=money($fixedExpenseCashPaidThisMonth)?></td><td><?=money($fixedExpenseMonthOutstanding)?></td><td><?=money($fixedAssetMonthDepreciation)?></td><td class="<?=$financeReportMonthAfterDepreciation < 0 ? 'danger-text' : ''?>"><?=money($financeReportMonthAfterDepreciation)?></td><td>折舊為非現金管理數；其他收入科目參考 <?=money($financeOtherIncomeTotal)?></td></tr></tbody></table></div>
  </section>


  <section class="ops-card ops-tab" id="finance-collection">
    <h2>財務系統 / 收款單</h2>
    <p class="muted">用來記錄客戶付款、訂金、尾款、匯款或現金收款。之後可串銷售單據、對帳單與公司帳。</p>

    <?php
      $receiptCustomerOptions = [];
      foreach ($members as $member) {
          $name = trim((string)($member['name'] ?? ''));
          if ($name === '') continue;
          $detail = trim(implode(' / ', array_filter([$member['facebook'] ?? '', $member['phone'] ?? ''], function($value) { return trim((string)$value) !== ''; })));
          $receiptCustomerOptions[$name] = $detail;
      }
      foreach ($schedules as $schedule) {
          $name = trim((string)($schedule['winner'] ?? ''));
          if ($name === '') continue;
          $detail = trim(implode(' / ', array_filter([$schedule['winner_facebook'] ?? '', $schedule['winner_phone'] ?? ''], function($value) { return trim((string)$value) !== ''; })));
          if (!isset($receiptCustomerOptions[$name]) || $receiptCustomerOptions[$name] === '') $receiptCustomerOptions[$name] = $detail;
      }
      foreach ($collectionReceipts as $receipt) {
          $name = trim((string)($receipt['customer_name'] ?? ''));
          if ($name !== '' && !isset($receiptCustomerOptions[$name])) $receiptCustomerOptions[$name] = '';
      }
      ksort($receiptCustomerOptions, SORT_NATURAL);
      $receiptDocumentCandidates = array_values(array_filter($allReconcileRows ?? [], function($row) { return ($row['outstanding'] ?? 0) > 0 && ($row['status'] ?? '') !== '已取消'; }));
    ?>
    <datalist id="receiptCustomerOptions">
      <?php foreach($receiptCustomerOptions as $customerName => $customerDetail): ?><option value="<?=h($customerName)?>"><?=h($customerDetail)?></option><?php endforeach; ?>
    </datalist>

    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_collection_receipt">
      <label>收款單號<input name="receipt_no" placeholder="系統可自動產生"></label>
      <label>收款日期<input name="receipt_date" type="date" value="<?=h(date('Y-m-d'))?>"></label>
      <label class="wide">客戶名稱<input id="receiptCustomerName" name="customer_name" list="receiptCustomerOptions" autocomplete="off" placeholder="輸入姓名、Facebook 名稱或電話後挑選"></label>
      <div class="customer-document-picker"><h3>這位客戶的未結單據</h3><div id="receiptCustomerDocuments" class="customer-document-list"><div class="customer-document-empty">請先輸入並選擇客戶，系統會列出可勾選的單據。</div></div></div>
      <label>收款方式<select name="payment_method"><option>現金</option><option>匯款</option><option>轉帳</option><option>刷卡</option><option>LINE Pay</option><option>其他</option></select></label>
      <label>收款帳戶<input name="account_name" placeholder="例如：現金 / 銀行 / 郵局"></label>
      <label>收款金額<input name="amount" type="number" step="1" value="0"></label>
      <label>狀態<select name="status"><option>待確認</option><option>已確認</option><option>部分收款</option><option>作廢</option></select></label>
      <label>經手人<input name="handler" placeholder="收款或確認人"></label>
      <label class="wide">備註<input name="note" placeholder="付款末五碼、訂金/尾款說明、異常備註"></label>
      <button class="primary">新增收款單</button>
    </form>
    <datalist id="reconcileDocumentNumbers">
      <?php foreach($schedules as $schedule): $scheduleProduct=product_by_id($products,$schedule['product_id']??''); $scheduleNo=trim((string)($schedule['order_no']??$schedule['id']??'')); if($scheduleNo==='') continue; ?>
        <option value="<?=h($scheduleNo)?>"><?=h(trim(($schedule['winner']??'').' / '.($schedule['product_title']??($scheduleProduct['title']??'')), ' /'))?></option>
      <?php endforeach; ?>
      <?php foreach($deliveryNotes as $delivery): if(trim((string)($delivery['delivery_no']??''))==='') continue; ?><option value="<?=h($delivery['delivery_no'])?>"><?=h(($delivery['buyer']['name']??'').' / 出貨單')?></option><?php endforeach; ?>
    </datalist>
    <script>window.receiptDocumentCandidates = <?=json_encode($receiptDocumentCandidates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;</script>

    <?php
      $receiptQ = trim($_GET['receipt_q'] ?? '');
      $receiptStatus = trim($_GET['receipt_status'] ?? '');
      $receiptFrom = trim($_GET['receipt_from'] ?? '');
      $receiptTo = trim($_GET['receipt_to'] ?? '');
      $receiptShown = array_values(array_filter($collectionReceipts, function($r) use ($receiptQ, $receiptStatus, $receiptFrom, $receiptTo) {
          $date = (string)($r['receipt_date'] ?? $r['date'] ?? '');
          $hay = implode(' ', [$r['receipt_no'] ?? '', $r['customer_name'] ?? '', $r['document_no'] ?? '', $r['payment_method'] ?? '', $r['account_name'] ?? '', $r['handler'] ?? '', $r['note'] ?? '']);
          if ($receiptQ !== '' && mb_stripos($hay, $receiptQ, 0, 'UTF-8') === false) return false;
          if ($receiptStatus !== '' && ($r['status'] ?? '') !== $receiptStatus) return false;
          if ($receiptFrom !== '' && $date < $receiptFrom) return false;
          if ($receiptTo !== '' && $date > $receiptTo) return false;
          return true;
      }));
      usort($receiptShown, function($a, $b) { return strcmp((string)($b['receipt_date'] ?? ''), (string)($a['receipt_date'] ?? '')); });
      $receiptTotal = array_sum(array_map(function($r) { return (float)($r['amount'] ?? 0); }, $receiptShown));
    ?>

    <div class="metric-grid">
      <div class="metric"><span>收款單筆數</span><strong><?=h(count($receiptShown))?> 筆</strong></div>
      <div class="metric"><span>收款合計</span><strong><?=money($receiptTotal)?></strong></div>
      <div class="metric"><span>已確認</span><strong><?=h(count(array_filter($receiptShown, function($r){ return ($r['status'] ?? '') === '已確認'; })))?> 筆</strong></div>
      <div class="metric"><span>待確認</span><strong><?=h(count(array_filter($receiptShown, function($r){ return ($r['status'] ?? '') === '待確認'; })))?> 筆</strong></div>
    </div>

    <form method="get" class="inline-actions">
      <input type="hidden" name="v" value="<?=h($_GET['v'] ?? '')?>">
      <label>搜尋<input name="receipt_q" value="<?=h($receiptQ)?>" placeholder="客戶 / 單號 / 帳戶 / 備註"></label>
      <label>狀態<select name="receipt_status"><option value="">全部</option><?php foreach(['待確認','已確認','部分收款','作廢'] as $v): ?><option value="<?=h($v)?>" <?=$receiptStatus===$v?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>起日<input name="receipt_from" type="date" value="<?=h($receiptFrom)?>"></label>
      <label>迄日<input name="receipt_to" type="date" value="<?=h($receiptTo)?>"></label>
      <button class="secondary">查詢</button>
      <a class="secondary-link" href="operations.php#finance-collection">清除</a>
    </form>

    <form id="collectionReceiptBulkDeleteForm" method="post" onsubmit="return confirm('確定刪除勾選收款單？');"><input type="hidden" name="action" value="delete_collection_receipts"></form>
    <div class="bulk-bar"><label class="check"><input type="checkbox" data-select-all="receipt_ids[]"> 全選收款單</label><button class="danger-button" type="submit" form="collectionReceiptBulkDeleteForm">刪除勾選收款單</button></div>
    <div class="table-wrap"><table><thead><tr><th>選</th><th>日期</th><th>收款單號</th><th>客戶</th><th>對應單號</th><th>方式</th><th>帳戶</th><th>金額</th><th>狀態</th><th>經手人</th><th>備註</th><th>操作</th></tr></thead><tbody>
      <?php if (!$receiptShown): ?><tr><td colspan="12" class="muted">目前沒有收款單資料。</td></tr><?php endif; ?>
      <?php foreach($receiptShown as $r): ?><tr>
        <td><input type="checkbox" name="receipt_ids[]" value="<?=h($r['id'] ?? '')?>" form="collectionReceiptBulkDeleteForm"></td>
        <form method="post">
          <input type="hidden" name="action" value="save_collection_receipt">
          <input type="hidden" name="receipt_id" value="<?=h($r['id'] ?? '')?>">
          <td><input name="receipt_date" type="date" value="<?=h($r['receipt_date'] ?? '')?>"></td>
          <td><input name="receipt_no" value="<?=h($r['receipt_no'] ?? '')?>"></td>
          <td><input name="customer_name" list="receiptCustomerOptions" autocomplete="off" value="<?=h($r['customer_name'] ?? '')?>"></td>
          <td><input name="document_no" list="reconcileDocumentNumbers" value="<?=h($r['document_no'] ?? '')?>"></td>
          <td><select name="payment_method"><?php foreach(['現金','匯款','轉帳','刷卡','LINE Pay','其他'] as $v): ?><option <?=$v===($r['payment_method'] ?? '')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td>
          <td><input name="account_name" value="<?=h($r['account_name'] ?? '')?>"></td>
          <td><input name="amount" type="number" step="1" value="<?=h($r['amount'] ?? 0)?>"></td>
          <td><select name="status"><?php foreach(['待確認','已確認','部分收款','作廢'] as $v): ?><option <?=$v===($r['status'] ?? '')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td>
          <td><input name="handler" value="<?=h($r['handler'] ?? '')?>"></td>
          <td><input name="note" value="<?=h($r['note'] ?? '')?>"></td>
          <td><button class="secondary small">儲存</button>
        </form>
        <form method="post" class="inline-form" onsubmit="return confirm('確定刪除此收款單？');">
          <input type="hidden" name="action" value="delete_collection_receipt">
          <input type="hidden" name="receipt_id" value="<?=h($r['id'] ?? '')?>">
          <button class="danger small">刪除</button>
        </form></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
  </section>



  <section class="ops-card ops-tab" id="finance-other-income">
    <h2>財務系統 / 其他收入科目</h2>
    <p class="muted">這裡先管理「其他收入科目底表」，例如賠償收入、發票、回收批案、外出費。之後收款單、維修單、回收批案或關稅物流有實際收入時，可用連動來源對應到這些科目，年度財報會用科目與收款流水一起核對。</p>

    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_finance_other_income">
      <label>科目編號<input name="account_code" placeholder="例如 3104"></label>
      <label>科目名稱<input name="name" placeholder="例如 飛斯特海運扣關賠償"></label>
      <label>科目簡名<input name="short_name" placeholder="顯示用簡名"></label>
      <label>拼音碼<input name="pinyin" placeholder="搜尋碼"></label>
      <label>收入類型<select name="income_type"><?php foreach(['賠償收入','發票/稅務','回收收入','外出服務','其他收入'] as $v): ?><option><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>連動來源<select name="link_source"><?php foreach(['手動核對','收款單','銷售單據','維修單據','回收批案','關稅/物流'] as $v): ?><option><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>週期<select name="frequency"><?php foreach(['依發生','每月','每年','一次性'] as $v): ?><option><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>參考金額/期末餘額<input name="ending_balance" type="number" step="1" value="0"></label>
      <label>狀態<select name="status"><?php foreach(['啟用','停用'] as $v): ?><option><?=h($v)?></option><?php endforeach; ?></select></label>
      <label class="span-2">備註<input name="note" placeholder="例如 由管家婆其他收入資料匯入，後續與收款單核對"></label>
      <button>新增其他收入科目</button>
    </form>

    <?php
      $incomeQ = trim($_GET['income_q'] ?? '');
      $incomeStatus = trim($_GET['income_status'] ?? '');
      $incomeType = trim($_GET['income_type'] ?? '');
      $incomeShown = array_values(array_filter($financeOtherIncome, function($r) use ($incomeQ, $incomeStatus, $incomeType) {
          $hay = implode(' ', [$r['account_code'] ?? '', $r['name'] ?? '', $r['short_name'] ?? '', $r['pinyin'] ?? '', $r['status'] ?? '', $r['income_type'] ?? '', $r['link_source'] ?? '', $r['frequency'] ?? '', $r['note'] ?? '']);
          if ($incomeQ !== '' && mb_stripos($hay, $incomeQ, 0, 'UTF-8') === false) return false;
          if ($incomeStatus !== '' && ($r['status'] ?? '') !== $incomeStatus) return false;
          if ($incomeType !== '' && ($r['income_type'] ?? '') !== $incomeType) return false;
          return true;
      }));
      usort($incomeShown, function($a, $b) { return strnatcasecmp((string)($a['account_code'] ?? ''), (string)($b['account_code'] ?? '')); });
      $incomeTotalBalance = array_sum(array_map(function($r) { return (float)($r['ending_balance'] ?? 0); }, $incomeShown));
      $incomeEnabledCount = count(array_filter($incomeShown, function($r){ return ($r['status'] ?? '') === '啟用'; }));
      $opsIncomeLimit = 10;
      $opsIncomePage = max(1, (int)($_GET['income_page'] ?? 1));
      $opsIncomeTotal = count($incomeShown);
      $opsIncomePages = max(1, (int)ceil($opsIncomeTotal / $opsIncomeLimit));
      $opsIncomePage = min($opsIncomePage, $opsIncomePages);
      $incomeShownPage = array_slice($incomeShown, ($opsIncomePage - 1) * $opsIncomeLimit, $opsIncomeLimit);
      $opsIncomePageUrl = function($page) use ($incomeQ, $incomeStatus, $incomeType) {
        return 'operations.php?' . http_build_query(['income_q' => $incomeQ, 'income_status' => $incomeStatus, 'income_type' => $incomeType, 'income_page' => $page]) . '#finance-other-income';
      };
    ?>

    <div class="metric-grid">
      <div class="metric"><span>目前科目數</span><strong><?=h(count($incomeShown))?> 筆</strong></div>
      <div class="metric"><span>其他收入科目合計</span><strong><?=money($incomeTotalBalance)?></strong></div>
      <div class="metric"><span>啟用科目</span><strong><?=h($incomeEnabledCount)?> 筆</strong></div>
      <div class="metric"><span>年度財報連動</span><strong><?=h($financeOtherIncomeYear)?> 年</strong></div>
    </div>

    <div class="ops-alert">連動方向：其他收入科目會進入公司財報收入端；之後若建立收款單、維修單或回收批案，可用「連動來源」對應到實際單據，年度合計會以科目與收款流水做核對。</div>

    <form method="get" class="inline-actions">
      <input type="hidden" name="v" value="<?=h($_GET['v'] ?? '')?>">
      <label>搜尋科目<input name="income_q" value="<?=h($incomeQ)?>" placeholder="編號 / 名稱 / 簡名 / 拼音 / 備註"></label>
      <label>類型<select name="income_type"><option value="">全部</option><?php foreach(['賠償收入','發票/稅務','回收收入','外出服務','其他收入'] as $v): ?><option value="<?=h($v)?>" <?=$incomeType===$v?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>狀態<select name="income_status"><option value="">全部</option><?php foreach(['啟用','停用'] as $v): ?><option value="<?=h($v)?>" <?=$incomeStatus===$v?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></label>
      <button class="secondary">查詢</button>
      <a class="secondary-link" href="operations.php#finance-other-income">清除</a>
    </form>

    <form id="financeOtherIncomeBulkDeleteForm" method="post" onsubmit="return confirm('確定刪除勾選的其他收入科目？');"><input type="hidden" name="action" value="delete_finance_other_incomes"></form>
    <div class="bulk-bar"><label class="check"><input type="checkbox" data-select-all="other_income_ids[]"> 全選其他收入科目</label><button class="danger-button" type="submit" form="financeOtherIncomeBulkDeleteForm">刪除勾選科目</button></div>
    <div class="table-wrap"><table><thead><tr><th>選</th><th>科目編號</th><th>科目名稱</th><th>類型</th><th>連動來源</th><th>週期</th><th>參考金額</th><th>狀態</th><th>備註</th><th>操作</th></tr></thead><tbody>
      <?php if (!$incomeShown): ?><tr><td colspan="10" class="muted">目前沒有符合條件的其他收入科目。</td></tr><?php endif; ?>
      <?php foreach($incomeShownPage as $r): ?><tr>
        <td><input type="checkbox" name="other_income_ids[]" value="<?=h($r['id'] ?? '')?>" form="financeOtherIncomeBulkDeleteForm"></td>
        <form method="post">
          <input type="hidden" name="action" value="save_finance_other_income">
          <input type="hidden" name="other_income_id" value="<?=h($r['id'] ?? '')?>">
          <td><input name="account_code" value="<?=h($r['account_code'] ?? '')?>"></td>
          <td><input name="name" value="<?=h($r['name'] ?? '')?>"><input name="short_name" value="<?=h($r['short_name'] ?? '')?>" placeholder="簡名"><input name="pinyin" value="<?=h($r['pinyin'] ?? '')?>" placeholder="拼音碼"></td>
          <td><select name="income_type"><?php foreach(['賠償收入','發票/稅務','回收收入','外出服務','其他收入'] as $v): ?><option <?=$v===($r['income_type'] ?? '')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td>
          <td><select name="link_source"><?php foreach(['手動核對','收款單','銷售單據','維修單據','回收批案','關稅/物流'] as $v): ?><option <?=$v===($r['link_source'] ?? '')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td>
          <td><select name="frequency"><?php foreach(['依發生','每月','每年','一次性'] as $v): ?><option <?=$v===($r['frequency'] ?? '')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td>
          <td><input name="ending_balance" type="number" step="1" value="<?=h($r['ending_balance'] ?? 0)?>"></td>
          <td><select name="status"><?php foreach(['啟用','停用'] as $v): ?><option <?=$v===($r['status'] ?? '')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td>
          <td><input name="note" value="<?=h($r['note'] ?? '')?>"></td>
          <td><button class="secondary small">儲存</button>
        </form>
        <form method="post" class="inline-form" onsubmit="return confirm('確定刪除此其他收入科目？');">
          <input type="hidden" name="action" value="delete_finance_other_income">
          <input type="hidden" name="other_income_id" value="<?=h($r['id'] ?? '')?>">
          <button class="danger small">刪除</button>
        </form></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if($opsIncomePages > 1): ?>
      <div class="pager"><span>第 <?=h($opsIncomePage)?> / <?=h($opsIncomePages)?> 頁，每頁 10 筆；顯示 <?=h(count($incomeShownPage))?> / <?=h($opsIncomeTotal)?> 筆</span><?php if($opsIncomePage > 1): ?><a class="secondary small" href="<?=h($opsIncomePageUrl($opsIncomePage - 1))?>">上一頁</a><?php endif; ?><?php if($opsIncomePage < $opsIncomePages): ?><a class="secondary small" href="<?=h($opsIncomePageUrl($opsIncomePage + 1))?>">下一頁</a><?php endif; ?></div>
    <?php endif; ?>
  </section>

  <section class="ops-card ops-tab" id="finance-request">
    <h2>財務系統 / 請款單</h2>
    <p class="muted">先選客戶，再勾選這位客戶尚未結清的單據。系統會依日期與單號排序，保存完整品項及金額明細。</p>
    <form method="post" class="product-form billing-create-form">
      <input type="hidden" name="action" value="save_billing_request">
      <label class="wide">客戶名稱<input id="billingCustomerName" name="billing_customer_name" list="receiptCustomerOptions" autocomplete="off" required placeholder="輸入客戶名稱後挑選"></label>
      <label>請款日期<input type="date" name="billing_request_date" value="<?=h(date('Y-m-d'))?>" required></label>
      <label>付款期限<input type="date" name="billing_due_date" value="<?=h(date('Y-m-d', strtotime('+7 days')))?>"></label>
      <label>狀態<select name="billing_status"><option>待請款</option><option>已送出</option><option>部分收款</option><option>已結清</option><option>取消</option></select></label>
      <div class="customer-document-picker"><h3>勾選要列入請款單的未結單據</h3><div id="billingCustomerDocuments" class="customer-document-list"><div class="customer-document-empty">請先選擇客戶。</div></div></div>
      <label class="wide">請款備註<input name="billing_note" placeholder="付款方式、匯款帳戶、聯絡說明"></label>
      <button class="primary">產生請款單</button>
    </form>

    <h3 class="reconcile-section-title">已建立請款單</h3>
    <?php if(!$billingRequests): ?><div class="customer-document-empty">目前尚未建立請款單。</div><?php endif; ?>
    <?php $billingRequestsSorted=$billingRequests; usort($billingRequestsSorted,function($a,$b){return strcmp(($b['request_date']??'').($b['request_no']??''),($a['request_date']??'').($a['request_no']??''));}); ?>
    <?php foreach($billingRequestsSorted as $request): ?>
      <details class="billing-request-card" id="billing-request-<?=h($request['id']??'')?>">
        <summary>
          <span><?=h($request['request_no']??'')?>｜<?=h($request['customer_name']??'')?></span>
          <span class="billing-request-meta"><span><?=h($request['request_date']??'')?></span><span>請款 <?=money($request['total_amount']??0)?></span><span><?=h($request['status']??'')?></span></span>
        </summary>
        <div class="billing-request-body">
          <div class="billing-request-meta"><b><?=h($companyProfile['company_name']??'寶輝科技有限公司')?></b><span>請款日期：<?=h($request['request_date']??'')?></span><span>付款期限：<?=h($request['due_date']??'')?></span><span>建立人：<?=h($request['operator']??'')?></span></div>
          <p><?=h($request['note']??'')?></p>
          <div class="table-wrap"><table class="billing-request-table"><thead><tr><th>排序</th><th>單據日期</th><th>來源單號</th><th>出貨單 / 發票</th><th>產品編號</th><th>品項</th><th>數量</th><th>原應收</th><th>已收</th><th>本次請款</th></tr></thead><tbody>
            <?php foreach(($request['items']??[]) as $itemIndex=>$item): ?><tr><td><?=h($itemIndex+1)?></td><td><?=h($item['date']??'')?></td><td><?=h($item['document_no']??'')?></td><td><?=h(trim(($item['delivery_no']??'').' / '.($item['invoice_no']??''),' /'))?></td><td><?=h($item['product_id']??'')?></td><td><?=h($item['product_title']??'')?></td><td><?=h($item['quantity']??0)?></td><td><?=money($item['receivable']??0)?></td><td><?=money($item['paid']??0)?></td><td><b><?=money($item['request_amount']??0)?></b></td></tr><?php endforeach; ?>
            <tr><td colspan="9" style="text-align:right"><b>請款總額</b></td><td><b><?=money($request['total_amount']??0)?></b></td></tr>
          </tbody></table></div>
          <div class="form-actions billing-request-actions"><button type="button" class="secondary print-billing-request" data-id="<?=h($request['id']??'')?>">列印橫式 A4</button><form method="post" onsubmit="return confirm('確定刪除此請款單？');"><input type="hidden" name="action" value="delete_billing_request"><input type="hidden" name="billing_request_id" value="<?=h($request['id']??'')?>"><button class="danger">刪除請款單</button></form></div>
        </div>
      </details>
    <?php endforeach; ?>
  </section>

  <section class="ops-card ops-tab" id="finance-bad-debt">
    <h2>財務系統 / 呆帳</h2>
    <p class="muted">從目前未收款資料轉入追蹤。轉入不會刪除應收帳款；追回款項會自動建立已確認收款單並回寫對帳資料。</p>
    <?php
      $activeBadDebtScheduleIds = [];
      foreach ($badDebts as $case) {
          if (in_array(($case['status'] ?? ''), ['已追回','已核准沖銷','取消'], true)) continue;
          foreach (($case['items'] ?? []) as $item) $activeBadDebtScheduleIds[$item['schedule_id'] ?? ''] = true;
      }
      $badDebtCandidates = array_values(array_filter($receiptDocumentCandidates ?? [], function($row) use ($activeBadDebtScheduleIds) { return ($row['outstanding'] ?? 0) > 0 && !isset($activeBadDebtScheduleIds[$row['schedule_id'] ?? '']); }));
      $badDebtCandidateCustomers = [];
      foreach ($badDebtCandidates as $row) $badDebtCandidateCustomers[$row['customer'] ?? ''] = true;
      ksort($badDebtCandidateCustomers, SORT_NATURAL);
      $badDebtTotal = array_sum(array_map(function($case){ return (float)($case['amount'] ?? 0); }, $badDebts));
      $badDebtRecovered = array_sum(array_map(function($case){ return (float)($case['recovered_amount'] ?? 0); }, $badDebts));
      $badDebtRemaining = array_sum(array_map(function($case){ return in_array(($case['status'] ?? ''), ['已核准沖銷','取消'], true) ? 0 : (float)($case['remaining_amount'] ?? 0); }, $badDebts));
      $badDebtOpenCount = count(array_filter($badDebts, function($case){ return !in_array(($case['status'] ?? ''), ['已追回','已核准沖銷','取消'], true); }));
      $badDebtsSorted = $badDebts;
      usort($badDebtsSorted, function($a,$b){ return strcmp(($b['recognition_date']??'').($b['case_no']??''),($a['recognition_date']??'').($a['case_no']??'')); });
    ?>
    <script>window.badDebtDocumentCandidates = <?=json_encode($badDebtCandidates, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?>;</script>
    <div class="metric-grid">
      <div class="metric"><span>累計轉入呆帳</span><strong><?=money($badDebtTotal)?></strong><small><?=h(count($badDebts))?> 件</small></div>
      <div class="metric"><span>已追回</span><strong><?=money($badDebtRecovered)?></strong><small>已同步收款對帳</small></div>
      <div class="metric"><span>尚待追回</span><strong class="danger-text"><?=money($badDebtRemaining)?></strong><small>不含已核准沖銷</small></div>
      <div class="metric"><span>進行中案件</span><strong><?=h($badDebtOpenCount)?> 件</strong><small>需持續追蹤</small></div>
    </div>
    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_bad_debt">
      <label class="wide">客戶名稱<input id="badDebtCustomerName" name="bad_debt_customer_name" list="badDebtCustomerOptions" autocomplete="off" required placeholder="選擇有未收款單據的客戶"></label>
      <datalist id="badDebtCustomerOptions"><?php foreach(array_keys($badDebtCandidateCustomers) as $customer): ?><option value="<?=h($customer)?>"></option><?php endforeach; ?></datalist>
      <label>轉入日期<input type="date" name="bad_debt_recognition_date" value="<?=h(date('Y-m-d'))?>" required></label>
      <label>原付款期限<input type="date" name="bad_debt_due_date"></label>
      <label>負責人<input name="bad_debt_owner" value="<?=h(current_operator())?>"></label>
      <label>下次追蹤日<input type="date" name="bad_debt_next_followup_at" value="<?=h(date('Y-m-d',strtotime('+3 days')))?>"></label>
      <label>呆帳原因<select name="bad_debt_reason"><option>逾期未付款</option><option>多次催收未回覆</option><option>棄標未付款</option><option>付款爭議</option><option>客戶失聯</option><option>法務處理</option><option>其他</option></select></label>
      <label>客戶電話<input name="bad_debt_customer_phone"></label>
      <div class="customer-document-picker"><h3>勾選要轉入呆帳的未結單據</h3><div id="badDebtCustomerDocuments" class="customer-document-list"><div class="customer-document-empty">請先選擇客戶。</div></div></div>
      <label class="wide">建案備註<input name="bad_debt_note" placeholder="催收背景、聯絡狀況、主管指示"></label>
      <button class="danger">建立呆帳案件</button>
    </form>

    <h3 class="reconcile-section-title">呆帳案件與催收紀錄</h3>
    <?php if(!$badDebtsSorted): ?><div class="customer-document-empty">目前沒有呆帳案件。</div><?php endif; ?>
    <?php foreach($badDebtsSorted as $case): ?>
      <details class="bad-debt-card">
        <summary><span><?=h($case['case_no']??'')?>｜<?=h($case['customer_name']??'')?></span><span class="billing-request-meta"><span><?=h($case['status']??'')?></span><span>原額 <?=money($case['amount']??0)?></span><span class="bad-debt-amount">未追回 <?=money($case['remaining_amount']??0)?></span></span></summary>
        <div class="bad-debt-body">
          <div class="billing-request-meta"><span>轉入：<?=h($case['recognition_date']??'')?></span><span>原期限：<?=h($case['original_due_date']??'')?></span><span>負責人：<?=h($case['owner']??'')?></span><span>下次追蹤：<?=h($case['next_followup_at']??'')?></span><span>原因：<?=h($case['reason']??'')?></span></div>
          <p><?=h($case['note']??'')?></p>
          <div class="table-wrap"><table class="billing-request-table"><thead><tr><th>排序</th><th>日期</th><th>來源單號</th><th>產品編號</th><th>品項</th><th>數量</th><th>原應收</th><th>已收</th><th>轉入呆帳</th></tr></thead><tbody><?php foreach(($case['items']??[]) as $itemIndex=>$item): ?><tr><td><?=h($itemIndex+1)?></td><td><?=h($item['date']??'')?></td><td><?=h($item['document_no']??'')?></td><td><?=h($item['product_id']??'')?></td><td><?=h($item['product_title']??'')?></td><td><?=h($item['quantity']??0)?></td><td><?=money($item['receivable']??0)?></td><td><?=money($item['paid']??0)?></td><td class="danger-text"><?=money($item['bad_debt_amount']??0)?></td></tr><?php endforeach; ?></tbody></table></div>
          <form method="post" class="product-form">
            <input type="hidden" name="action" value="update_bad_debt"><input type="hidden" name="bad_debt_id" value="<?=h($case['id']??'')?>">
            <label>案件狀態<select name="bad_debt_status"><?php foreach(['待催收','催收中','承諾付款','部分追回','已追回','法務處理','申請沖銷','已核准沖銷','取消'] as $status): ?><option value="<?=h($status)?>" <?=$status===($case['status']??'')?'selected':''?>><?=h($status)?></option><?php endforeach; ?></select></label>
            <label>本次追回金額<input type="number" name="bad_debt_recovered_increment" min="0" max="<?=h($case['remaining_amount']??0)?>" step="1" value="0"></label>
            <label>實際收款方式<select name="bad_debt_payment_method"><option>匯款</option><option>轉帳</option><option>現金</option><option>刷卡</option><option>LINE Pay</option><option>其他</option></select></label>
            <label>收款帳戶<input name="bad_debt_account_name" placeholder="銀行 / 現金 / 郵局"></label>
            <label>聯絡日期<input type="date" name="bad_debt_contact_date" value="<?=h(date('Y-m-d'))?>"></label>
            <label>催收方式<select name="bad_debt_collection_method"><option>電話</option><option>LINE</option><option>簡訊</option><option>電子郵件</option><option>存證信函</option><option>法務</option><option>其他</option></select></label>
            <label>負責人<input name="bad_debt_owner" value="<?=h($case['owner']??'')?>"></label>
            <label>下次追蹤日<input type="date" name="bad_debt_next_followup_at" value="<?=h($case['next_followup_at']??'')?>"></label>
            <label class="wide">本次聯絡結果<input name="bad_debt_followup_result" placeholder="對方回覆、承諾付款日、無法聯絡等"></label>
            <label>沖銷日期<input type="date" name="bad_debt_writeoff_date" value="<?=h($case['writeoff_date']??'')?>"></label>
            <label>核准人<input name="bad_debt_approval_by" value="<?=h($case['approval_by']??'')?>"></label>
            <label class="wide">沖銷原因<input name="bad_debt_writeoff_reason" value="<?=h($case['writeoff_reason']??'')?>" placeholder="選擇已核准沖銷時必填"></label>
            <button class="primary">儲存追蹤紀錄</button>
          </form>
          <h4>歷次催收</h4>
          <div class="table-wrap"><table class="bad-debt-followups"><thead><tr><th>日期</th><th>方式</th><th>結果</th><th>追回金額</th><th>下次追蹤</th><th>紀錄人</th></tr></thead><tbody><?php foreach(array_reverse($case['followups']??[]) as $followup): ?><tr><td><?=h($followup['date']??'')?></td><td><?=h($followup['method']??'')?></td><td><?=h($followup['result']??'')?></td><td><?=money($followup['recovered_amount']??0)?></td><td><?=h($followup['next_followup_at']??'')?></td><td><?=h($followup['operator']??'')?></td></tr><?php endforeach; ?><?php if(empty($case['followups'])): ?><tr><td colspan="6" class="muted">尚無催收紀錄。</td></tr><?php endif; ?></tbody></table></div>
          <form method="post" onsubmit="return confirm('確定刪除此呆帳案件？');"><input type="hidden" name="action" value="delete_bad_debt"><input type="hidden" name="bad_debt_id" value="<?=h($case['id']??'')?>"><button class="danger small">刪除案件</button></form>
        </div>
      </details>
    <?php endforeach; ?>
  </section>

  <section class="ops-card ops-tab" id="finance-loss">
    <h2>盤點系統 / 報損單</h2>
    <p class="muted">掃產品條碼或產品編號建立報損，儲存後會扣減總庫存並寫入庫存異動紀錄。</p>
    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_inventory_adjustment">
      <input type="hidden" name="adjust_type" value="loss">
      <label>日期<input type="date" name="adjust_date" value="<?=h(date('Y-m-d'))?>"></label>
      <label>產品條碼 / 編號<input name="adjust_code" placeholder="掃描或輸入產品條碼"></label>
      <label>數量<input type="number" name="adjust_qty" min="1" value="1"></label>
      <label>狀態<select name="adjust_status"><option>已登記</option><option>待核准</option><option>已核准</option><option>取消</option></select></label>
      <label class="wide">報損原因<input name="adjust_reason" placeholder="損壞、遺失、不可售、報廢等"></label>
      <button>建立報損並扣庫存</button>
    </form>
    <div class="table-wrap"><table><thead><tr><th>日期</th><th>報損單號</th><th>品項</th><th>數量</th><th>庫存前後</th><th>成本</th><th>原因</th><th>狀態</th><th>建立人</th></tr></thead><tbody>
      <?php $lossRows = array_values(array_filter($inventoryAdjustments, function($r){ return ($r['type'] ?? '') === 'loss'; })); ?>
      <?php if(!$lossRows): ?><tr><td colspan="9" class="muted">尚未建立報損單。</td></tr><?php endif; ?>
      <?php foreach(array_reverse($lossRows) as $row): ?>
      <tr><td><?=h($row['date'] ?? '')?></td><td><?=h($row['doc_no'] ?? '')?></td><td><?=h(product_scan_label($row, $row['product_id'] ?? ''))?></td><td><?=h($row['qty'] ?? 0)?></td><td><?=h(($row['before_qty'] ?? 0) . ' → ' . ($row['after_qty'] ?? 0))?></td><td><?=h(money(($row['unit_cost'] ?? 0) * ($row['qty'] ?? 0)))?></td><td><?=h($row['reason'] ?? '')?></td><td><?=h($row['status'] ?? '')?></td><td><?=h($row['operator'] ?? '')?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>

  <section class="ops-card ops-tab" id="finance-overage">
    <h2>盤點系統 / 報溢單</h2>
    <p class="muted">掃產品條碼或產品編號建立報溢，儲存後會增加總庫存並寫入庫存異動紀錄。</p>
    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_inventory_adjustment">
      <input type="hidden" name="adjust_type" value="overage">
      <label>日期<input type="date" name="adjust_date" value="<?=h(date('Y-m-d'))?>"></label>
      <label>產品條碼 / 編號<input name="adjust_code" placeholder="掃描或輸入產品條碼"></label>
      <label>數量<input type="number" name="adjust_qty" min="1" value="1"></label>
      <label>狀態<select name="adjust_status"><option>已登記</option><option>待核准</option><option>已核准</option><option>取消</option></select></label>
      <label class="wide">報溢原因<input name="adjust_reason" placeholder="盤點多出、補登、廠商補貨差異等"></label>
      <button>建立報溢並加庫存</button>
    </form>
    <div class="table-wrap"><table><thead><tr><th>日期</th><th>報溢單號</th><th>品項</th><th>數量</th><th>庫存前後</th><th>成本</th><th>原因</th><th>狀態</th><th>建立人</th></tr></thead><tbody>
      <?php $overageRows = array_values(array_filter($inventoryAdjustments, function($r){ return ($r['type'] ?? '') === 'overage'; })); ?>
      <?php if(!$overageRows): ?><tr><td colspan="9" class="muted">尚未建立報溢單。</td></tr><?php endif; ?>
      <?php foreach(array_reverse($overageRows) as $row): ?>
      <tr><td><?=h($row['date'] ?? '')?></td><td><?=h($row['doc_no'] ?? '')?></td><td><?=h(product_scan_label($row, $row['product_id'] ?? ''))?></td><td><?=h($row['qty'] ?? 0)?></td><td><?=h(($row['before_qty'] ?? 0) . ' → ' . ($row['after_qty'] ?? 0))?></td><td><?=h(money(($row['unit_cost'] ?? 0) * ($row['qty'] ?? 0)))?></td><td><?=h($row['reason'] ?? '')?></td><td><?=h($row['status'] ?? '')?></td><td><?=h($row['operator'] ?? '')?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>



  <section class="ops-card ops-tab" id="finance-expense-categories">
    <h2>財務系統 / 費用支出科目</h2>
    <p class="muted">這裡用來管理固定開支、薪資獎金、業務提成、車資維修、物流虧損、報損呆帳等費用科目。今天匯入的是「費用科目底表」，不是單筆付款流水帳。</p>

    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_finance_expense_category">
      <label>科目編號<input name="account_code" placeholder="例如 0503"></label>
      <label>科目名稱<input name="name" placeholder="例如 電動車充電卡"></label>
      <label>科目簡名<input name="short_name" placeholder="顯示用簡名"></label>
      <label>拼音碼<input name="pinyin" placeholder="搜尋碼"></label>
      <label>類型<select name="expense_type"><?php foreach(['薪資/獎金','業務獎金','車資/維修保養','物流/運費','損失/呆帳','行政/差旅','一般支出'] as $v): ?><option><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>連動來源<select name="link_source"><?php foreach(['寶輝薪資','張張營收/業績','車輛維修','物流/關稅','手動核對'] as $v): ?><option><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>週期<select name="frequency"><?php foreach(['每月','每年','依發生','一次性'] as $v): ?><option><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>參考金額/期末餘額<input name="ending_balance" type="number" step="1" value="0"></label>
      <label>狀態<select name="status"><option>啟用</option><option>停用</option></select></label>
      <label class="check"><input type="checkbox" name="is_fixed_expense" value="1"> 固定開支</label>
      <label class="wide">備註<input name="note" placeholder="使用規則、核對方式或注意事項"></label>
      <button class="primary">新增費用科目</button>
    </form>

    <?php
      $expenseQ = trim($_GET['expense_q'] ?? '');
      $expenseStatus = trim($_GET['expense_status'] ?? '');
      $expenseType = trim($_GET['expense_type'] ?? '');
      $expenseShown = array_values(array_filter($financeExpenseCategories, function($r) use ($expenseQ, $expenseStatus, $expenseType) {
          $hay = implode(' ', [$r['account_code'] ?? '', $r['name'] ?? '', $r['short_name'] ?? '', $r['pinyin'] ?? '', $r['status'] ?? '', $r['expense_type'] ?? '', $r['link_source'] ?? '', $r['frequency'] ?? '', $r['note'] ?? '']);
          if ($expenseQ !== '' && mb_stripos($hay, $expenseQ, 0, 'UTF-8') === false) return false;
          if ($expenseStatus !== '' && ($r['status'] ?? '') !== $expenseStatus) return false;
          if ($expenseType !== '' && ($r['expense_type'] ?? '') !== $expenseType) return false;
          return true;
      }));
      usort($expenseShown, function($a, $b) { return strnatcasecmp((string)($a['account_code'] ?? ''), (string)($b['account_code'] ?? '')); });
      $expenseTotalBalance = array_sum(array_map(function($r) { return (float)($r['ending_balance'] ?? 0); }, $expenseShown));
      $expenseFixedCount = count(array_filter($expenseShown, function($r){ return !empty($r['is_fixed_expense']); }));
      $expenseEnabledCount = count(array_filter($expenseShown, function($r){ return ($r['status'] ?? '') === '啟用'; }));
      $opsExpenseLimit = 10;
      $opsExpensePage = max(1, (int)($_GET['expense_page'] ?? 1));
      $opsExpenseTotal = count($expenseShown);
      $opsExpensePages = max(1, (int)ceil($opsExpenseTotal / $opsExpenseLimit));
      $opsExpensePage = min($opsExpensePage, $opsExpensePages);
      $expenseShownPage = array_slice($expenseShown, ($opsExpensePage - 1) * $opsExpenseLimit, $opsExpenseLimit);
      $opsExpensePageUrl = function($page) use ($expenseQ, $expenseStatus, $expenseType) {
        return 'operations.php?' . http_build_query(['expense_q' => $expenseQ, 'expense_status' => $expenseStatus, 'expense_type' => $expenseType, 'expense_page' => $page]) . '#finance-expense-categories';
      };
    ?>

    <div class="metric-grid">
      <div class="metric"><span>目前科目數</span><strong><?=h(count($expenseShown))?> 筆</strong></div>
      <div class="metric"><span>參考金額合計</span><strong><?=money($expenseTotalBalance)?></strong></div>
      <div class="metric"><span>啟用科目</span><strong><?=h($expenseEnabledCount)?> 筆</strong></div>
      <div class="metric"><span>固定開支</span><strong><?=h($expenseFixedCount)?> 筆</strong></div>
    </div>

    <div class="ops-alert">核對方向：薪資/獎金可對寶輝總部人員薪資；業務獎金可對張張營收提成；車資維修與保養走手動核對；物流/運費與關稅核實費用可串關稅與物流資料。</div>

    <form method="get" class="inline-actions">
      <input type="hidden" name="v" value="<?=h($_GET['v'] ?? '')?>">
      <label>搜尋科目<input name="expense_q" value="<?=h($expenseQ)?>" placeholder="編號 / 名稱 / 簡名 / 拼音 / 備註"></label>
      <label>類型<select name="expense_type"><option value="">全部</option><?php foreach(['薪資/獎金','業務獎金','車資/維修保養','物流/運費','損失/呆帳','行政/差旅','一般支出'] as $v): ?><option value="<?=h($v)?>" <?=$expenseType===$v?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></label>
      <label>狀態<select name="expense_status"><option value="">全部</option><?php foreach(['啟用','停用'] as $v): ?><option value="<?=h($v)?>" <?=$expenseStatus===$v?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></label>
      <button class="secondary">查詢</button>
      <a class="secondary-link" href="operations.php#finance-expense-categories">清除</a>
    </form>

    <form id="financeExpenseCategoryBulkDeleteForm" method="post" onsubmit="return confirm('確定刪除勾選的費用支出科目？');"><input type="hidden" name="action" value="delete_finance_expense_categories"></form>
    <div class="bulk-bar"><label class="check"><input type="checkbox" data-select-all="expense_category_ids[]"> 全選費用科目</label><button class="danger-button" type="submit" form="financeExpenseCategoryBulkDeleteForm">刪除勾選科目</button></div>
    <div class="table-wrap"><table><thead><tr><th>選</th><th>科目編號</th><th>科目名稱</th><th>類型</th><th>連動來源</th><th>週期</th><th>參考金額</th><th>固定</th><th>狀態</th><th>備註</th><th>操作</th></tr></thead><tbody>
      <?php if (!$expenseShown): ?><tr><td colspan="11" class="muted">目前沒有符合條件的費用支出科目。</td></tr><?php endif; ?>
      <?php foreach($expenseShownPage as $r): ?><tr>
        <td><input type="checkbox" name="expense_category_ids[]" value="<?=h($r['id'] ?? '')?>" form="financeExpenseCategoryBulkDeleteForm"></td>
        <form method="post">
          <input type="hidden" name="action" value="save_finance_expense_category">
          <input type="hidden" name="expense_category_id" value="<?=h($r['id'] ?? '')?>">
          <td><input name="account_code" value="<?=h($r['account_code'] ?? '')?>"></td>
          <td><input name="name" value="<?=h($r['name'] ?? '')?>"><input name="short_name" value="<?=h($r['short_name'] ?? '')?>" placeholder="簡名"><input name="pinyin" value="<?=h($r['pinyin'] ?? '')?>" placeholder="拼音碼"></td>
          <td><select name="expense_type"><?php foreach(['薪資/獎金','業務獎金','車資/維修保養','物流/運費','損失/呆帳','行政/差旅','一般支出'] as $v): ?><option <?=$v===($r['expense_type'] ?? '')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td>
          <td><select name="link_source"><?php foreach(['寶輝薪資','張張營收/業績','車輛維修','物流/關稅','手動核對'] as $v): ?><option <?=$v===($r['link_source'] ?? '')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td>
          <td><select name="frequency"><?php foreach(['每月','每年','依發生','一次性'] as $v): ?><option <?=$v===($r['frequency'] ?? '')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td>
          <td><input name="ending_balance" type="number" step="1" value="<?=h($r['ending_balance'] ?? 0)?>"></td>
          <td><label class="check"><input type="checkbox" name="is_fixed_expense" value="1" <?=!empty($r['is_fixed_expense'])?'checked':''?>> 是</label></td>
          <td><select name="status"><?php foreach(['啟用','停用'] as $v): ?><option <?=$v===($r['status'] ?? '')?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select></td>
          <td><input name="note" value="<?=h($r['note'] ?? '')?>"></td>
          <td><button class="secondary small">儲存</button>
        </form>
        <form method="post" class="inline-form" onsubmit="return confirm('確定刪除此費用支出科目？');">
          <input type="hidden" name="action" value="delete_finance_expense_category">
          <input type="hidden" name="expense_category_id" value="<?=h($r['id'] ?? '')?>">
          <button class="danger small">刪除</button>
        </form></td>
      </tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if($opsExpensePages > 1): ?>
      <div class="pager"><span>第 <?=h($opsExpensePage)?> / <?=h($opsExpensePages)?> 頁，每頁 10 筆；顯示 <?=h(count($expenseShownPage))?> / <?=h($opsExpenseTotal)?> 筆</span><?php if($opsExpensePage > 1): ?><a class="secondary small" href="<?=h($opsExpensePageUrl($opsExpensePage - 1))?>">上一頁</a><?php endif; ?><?php if($opsExpensePage < $opsExpensePages): ?><a class="secondary small" href="<?=h($opsExpensePageUrl($opsExpensePage + 1))?>">下一頁</a><?php endif; ?></div>
    <?php endif; ?>
  </section>

  <section class="ops-card ops-tab" id="finance-fixed-expense">
    <h2>財務系統 / 固定開支</h2>
    <p class="muted">固定開支設定會連動費用科目與廠商資料，系統依週期自動建立每期應付紀錄；只有標記「已付款」的金額才會計入公司財報實際支出。</p>
    <?php
      $fixedPeriod = trim((string)($_GET['fixed_period'] ?? date('Y-m')));
      if (!preg_match('/^\d{4}-\d{2}$/', $fixedPeriod)) $fixedPeriod = date('Y-m');
      $fixedStatus = trim((string)($_GET['fixed_status'] ?? ''));
      $fixedQ = trim((string)($_GET['fixed_q'] ?? ''));
      $fixedPaymentRows = array_values(array_filter($fixedExpensePayments, function($row) use ($fixedPeriod, $fixedStatus, $fixedQ) {
          if (($row['period'] ?? '') !== $fixedPeriod) return false;
          if ($fixedStatus !== '' && ($row['status'] ?? '') !== $fixedStatus) return false;
          $hay = implode(' ', [$row['payment_no'] ?? '', $row['expense_name'] ?? '', $row['category_name'] ?? '', $row['account_code'] ?? '', $row['supplier_name'] ?? '', $row['invoice_no'] ?? '', $row['handler'] ?? '', $row['note'] ?? '']);
          return $fixedQ === '' || mb_stripos($hay, $fixedQ, 0, 'UTF-8') !== false;
      }));
      usort($fixedPaymentRows, function($a, $b) { return strcmp(($a['due_date'] ?? '') . ($a['payment_no'] ?? ''), ($b['due_date'] ?? '') . ($b['payment_no'] ?? '')); });
      $fixedPeriodPayable = array_sum(array_map(function($row) { return in_array(($row['status'] ?? ''), ['免付','取消'], true) ? 0 : (float)($row['amount'] ?? 0); }, $fixedPaymentRows));
      $fixedPeriodPaid = array_sum(array_map(function($row) { return ($row['status'] ?? '') === '已付款' ? (float)($row['amount'] ?? 0) : 0; }, $fixedPaymentRows));
      $fixedPeriodOutstanding = array_sum(array_map(function($row) { return in_array(($row['status'] ?? ''), ['待付款','待確認','逾期'], true) ? (float)($row['amount'] ?? 0) : 0; }, $fixedPaymentRows));
      $fixedPeriodOverdueCount = count(array_filter($fixedPaymentRows, function($row) { return ($row['status'] ?? '') === '逾期'; }));
      $fixedPage = max(1, (int)($_GET['fixed_page'] ?? 1));
      $fixedLimit = 10;
      $fixedTotal = count($fixedPaymentRows);
      $fixedPages = max(1, (int)ceil($fixedTotal / $fixedLimit));
      $fixedPage = min($fixedPage, $fixedPages);
      $fixedPaymentRowsPage = array_slice($fixedPaymentRows, ($fixedPage - 1) * $fixedLimit, $fixedLimit);
      $fixedPageUrl = function($page) use ($fixedPeriod, $fixedStatus, $fixedQ) {
          return 'operations.php?' . http_build_query(['fixed_period'=>$fixedPeriod,'fixed_status'=>$fixedStatus,'fixed_q'=>$fixedQ,'fixed_page'=>$page]) . '#finance-fixed-expense';
      };
      $fixedCategories = $financeExpenseCategories;
      usort($fixedCategories, function($a, $b) {
          $fixedRank = (int)!empty($b['is_fixed_expense']) <=> (int)!empty($a['is_fixed_expense']);
          return $fixedRank ?: strnatcasecmp((string)($a['account_code'] ?? ''), (string)($b['account_code'] ?? ''));
      });
      $fixedTemplatesSorted = $fixedExpenses;
      usort($fixedTemplatesSorted, function($a, $b) { return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')); });
    ?>

    <div class="metric-grid">
      <div class="metric"><span><?=h($fixedPeriod)?> 應付合計</span><strong><?=money($fixedPeriodPayable)?></strong><small><?=h(count($fixedPaymentRows))?> 筆</small></div>
      <div class="metric"><span>已付款</span><strong><?=money($fixedPeriodPaid)?></strong></div>
      <div class="metric"><span>待付款</span><strong><?=money($fixedPeriodOutstanding)?></strong></div>
      <div class="metric"><span>逾期</span><strong class="<?=$fixedPeriodOverdueCount > 0 ? 'danger-text' : ''?>"><?=h($fixedPeriodOverdueCount)?> 筆</strong></div>
    </div>

    <h3>新增固定開支設定</h3>
    <?php if(!$fixedCategories): ?><div class="ops-alert">請先到「費用支出科目」建立至少一個啟用科目，再新增固定開支。</div><?php endif; ?>
    <form method="post" class="product-form">
      <input type="hidden" name="action" value="save_fixed_expense">
      <label>項目名稱<input name="name" required placeholder="例如 辦公室租金"></label>
      <label>費用科目<select name="expense_category_id" required><option value="">請選擇</option><?php foreach($fixedCategories as $category): ?><option value="<?=h($category['id'] ?? '')?>"><?=h(trim(($category['account_code'] ?? '') . ' ' . ($category['name'] ?? '')))?><?=!empty($category['is_fixed_expense'])?'（固定）':''?></option><?php endforeach; ?></select></label>
      <label>廠商<select name="supplier_id"><option value="">未指定 / 臨時廠商</option><?php foreach($suppliers as $supplier): ?><option value="<?=h($supplier['id'] ?? '')?>"><?=h($supplier['name'] ?? '')?></option><?php endforeach; ?></select></label>
      <label>臨時廠商名稱<input name="supplier_name" placeholder="未建檔時可輸入"></label>
      <label>週期<select name="frequency"><?php foreach(['每月','每季','每半年','每年'] as $value): ?><option><?=h($value)?></option><?php endforeach; ?></select></label>
      <label>金額方式<select name="amount_type"><option>固定金額</option><option>依帳單</option></select></label>
      <label>預設金額<input name="default_amount" type="number" min="0" step="1" value="0"></label>
      <label>每期付款日<input name="payment_day" type="number" min="1" max="31" value="1"></label>
      <label>開始日期<input name="start_date" type="date" value="<?=h(date('Y-m-d'))?>" required></label>
      <label>結束日期<input name="end_date" type="date"></label>
      <label>付款方式<select name="payment_method"><?php foreach(['轉帳','現金','刷卡','自動扣款','支票','其他'] as $value): ?><option><?=h($value)?></option><?php endforeach; ?></select></label>
      <label>付款帳戶<input name="account_name" placeholder="例如 公司銀行帳戶"></label>
      <label>部門 / 成本中心<input name="cost_center" placeholder="例如 總公司 / 電商部"></label>
      <label>狀態<select name="status"><option>啟用</option><option>暫停</option><option>結束</option></select></label>
      <label class="check"><input type="checkbox" name="auto_generate" value="1" checked> 自動產生每期應付</label>
      <label class="wide">備註<input name="note" placeholder="合約、計價或核對說明"></label>
      <button class="primary" <?=$fixedCategories?'':'disabled'?>>儲存固定開支</button>
    </form>

    <h3>固定開支設定清單</h3>
    <div class="fixed-expense-list">
      <?php foreach($fixedTemplatesSorted as $template): ?>
        <details class="fixed-expense-editor">
          <summary><span><b><?=h($template['name'] ?? '')?></b><small><?=h(trim(($template['account_code'] ?? '') . ' ' . ($template['category_name'] ?? '')))?><?=trim((string)($template['supplier_name'] ?? '')) !== '' ? '｜' . h($template['supplier_name']) : ''?></small></span><span><?=h($template['frequency'] ?? '')?>｜<?=h($template['amount_type'] ?? '')?> <?=money($template['default_amount'] ?? 0)?>｜<?=h($template['status'] ?? '')?></span></summary>
          <form method="post" class="product-form fixed-expense-edit-form">
            <input type="hidden" name="action" value="save_fixed_expense"><input type="hidden" name="fixed_expense_id" value="<?=h($template['id'] ?? '')?>">
            <label>項目名稱<input name="name" value="<?=h($template['name'] ?? '')?>" required></label>
            <label>費用科目<select name="expense_category_id" required><?php foreach($fixedCategories as $category): ?><option value="<?=h($category['id'] ?? '')?>" <?=($category['id']??'')===($template['expense_category_id']??'')?'selected':''?>><?=h(trim(($category['account_code'] ?? '') . ' ' . ($category['name'] ?? '')))?></option><?php endforeach; ?></select></label>
            <label>廠商<select name="supplier_id"><option value="">未指定 / 臨時廠商</option><?php foreach($suppliers as $supplier): ?><option value="<?=h($supplier['id'] ?? '')?>" <?=($supplier['id']??'')===($template['supplier_id']??'')?'selected':''?>><?=h($supplier['name'] ?? '')?></option><?php endforeach; ?></select></label>
            <label>臨時廠商名稱<input name="supplier_name" value="<?=h($template['supplier_name'] ?? '')?>"></label>
            <label>週期<select name="frequency"><?php foreach(['每月','每季','每半年','每年'] as $value): ?><option <?=$value===($template['frequency']??'')?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label>
            <label>金額方式<select name="amount_type"><?php foreach(['固定金額','依帳單'] as $value): ?><option <?=$value===($template['amount_type']??'')?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label>
            <label>預設金額<input name="default_amount" type="number" min="0" step="1" value="<?=h($template['default_amount'] ?? 0)?>"></label>
            <label>付款日<input name="payment_day" type="number" min="1" max="31" value="<?=h($template['payment_day'] ?? 1)?>"></label>
            <label>開始日期<input name="start_date" type="date" value="<?=h($template['start_date'] ?? '')?>"></label>
            <label>結束日期<input name="end_date" type="date" value="<?=h($template['end_date'] ?? '')?>"></label>
            <label>付款方式<select name="payment_method"><?php foreach(['轉帳','現金','刷卡','自動扣款','支票','其他'] as $value): ?><option <?=$value===($template['payment_method']??'')?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label>
            <label>付款帳戶<input name="account_name" value="<?=h($template['account_name'] ?? '')?>"></label>
            <label>部門 / 成本中心<input name="cost_center" value="<?=h($template['cost_center'] ?? '')?>"></label>
            <label>狀態<select name="status"><?php foreach(['啟用','暫停','結束'] as $value): ?><option <?=$value===($template['status']??'')?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label>
            <label class="check"><input type="checkbox" name="auto_generate" value="1" <?=!empty($template['auto_generate'])?'checked':''?>> 自動產生</label>
            <label class="wide">備註<input name="note" value="<?=h($template['note'] ?? '')?>"></label>
            <button class="secondary">更新設定</button>
          </form>
          <form method="post" class="inline-form fixed-expense-delete" onsubmit="return confirm('確定刪除此固定開支設定？既有付款歷史會保留。');"><input type="hidden" name="action" value="delete_fixed_expense"><input type="hidden" name="fixed_expense_id" value="<?=h($template['id'] ?? '')?>"><button class="danger small">刪除設定</button></form>
        </details>
      <?php endforeach; ?>
      <?php if(!$fixedTemplatesSorted): ?><div class="customer-document-empty">尚未建立固定開支設定。</div><?php endif; ?>
    </div>

    <div class="section-head fixed-expense-payment-head"><div><h3>每期應付與付款紀錄</h3><p class="muted">「依帳單」項目產生時金額為 0，收到帳單後在這裡填入實際金額。</p></div><form method="post" class="inline-actions"><input type="hidden" name="action" value="generate_fixed_expense_period"><label>產生指定月份<input type="month" name="fixed_expense_period" value="<?=h($fixedPeriod)?>"></label><button class="secondary">產生應付紀錄</button></form></div>
    <form method="get" action="operations.php#finance-fixed-expense" class="inline-actions fixed-expense-filter">
      <label>月份<input type="month" name="fixed_period" value="<?=h($fixedPeriod)?>"></label>
      <label>狀態<select name="fixed_status"><option value="">全部</option><?php foreach(['待付款','待確認','已付款','逾期','免付','取消'] as $value): ?><option value="<?=h($value)?>" <?=$fixedStatus===$value?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label>
      <label>關鍵字<input name="fixed_q" value="<?=h($fixedQ)?>" placeholder="項目 / 科目 / 廠商 / 單號"></label>
      <button class="secondary">查詢</button><a class="secondary-link" href="operations.php?fixed_period=<?=h($fixedPeriod)?>#finance-fixed-expense">清除條件</a>
    </form>
    <div class="table-wrap"><table class="fixed-expense-payment-table"><thead><tr><th>到期日</th><th>付款單號</th><th>項目 / 科目</th><th>廠商</th><th>金額</th><th>狀態</th><th>付款日</th><th>帳戶 / 發票</th><th>操作</th></tr></thead><tbody>
      <?php foreach($fixedPaymentRowsPage as $payment): ?><tr class="<?=($payment['status']??'')==='逾期'?'fixed-expense-overdue':''?>"><td><?=h($payment['due_date'] ?? '')?></td><td><?=h($payment['payment_no'] ?? '')?></td><td><b><?=h($payment['expense_name'] ?? '')?></b><small><?=h(trim(($payment['account_code'] ?? '') . ' ' . ($payment['category_name'] ?? '')))?></small></td><td><?=h($payment['supplier_name'] ?? '')?></td><td><?=money($payment['amount'] ?? 0)?></td><td><span class="status-pill"><?=h($payment['status'] ?? '')?></span></td><td><?=h($payment['paid_date'] ?? '')?></td><td><?=h($payment['account_name'] ?? '')?><small><?=h($payment['invoice_no'] ?? '')?></small></td><td><details class="fixed-payment-editor"><summary>編輯</summary><form method="post" class="product-form"><input type="hidden" name="action" value="save_fixed_expense_payment"><input type="hidden" name="fixed_expense_payment_id" value="<?=h($payment['id'] ?? '')?>"><label>到期日<input name="due_date" type="date" value="<?=h($payment['due_date'] ?? '')?>"></label><label>實際金額<input name="amount" type="number" min="0" step="1" value="<?=h($payment['amount'] ?? 0)?>"></label><label>狀態<select name="status"><?php foreach(['待付款','待確認','已付款','逾期','免付','取消'] as $value): ?><option <?=$value===($payment['status']??'')?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label><label>付款日<input name="paid_date" type="date" value="<?=h($payment['paid_date'] ?? '')?>"></label><label>付款方式<select name="payment_method"><?php foreach(['轉帳','現金','刷卡','自動扣款','支票','其他'] as $value): ?><option <?=$value===($payment['payment_method']??'')?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label><label>付款帳戶<input name="account_name" value="<?=h($payment['account_name'] ?? '')?>"></label><label>發票 / 憑證號<input name="invoice_no" value="<?=h($payment['invoice_no'] ?? '')?>"></label><label>經手人<input name="handler" value="<?=h($payment['handler'] ?? '')?>"></label><label class="wide">備註<input name="note" value="<?=h($payment['note'] ?? '')?>"></label><button class="primary">儲存付款資料</button></form><form method="post" class="inline-form" onsubmit="return confirm('確定刪除此付款紀錄？');"><input type="hidden" name="action" value="delete_fixed_expense_payment"><input type="hidden" name="fixed_expense_payment_id" value="<?=h($payment['id'] ?? '')?>"><button class="danger small">刪除紀錄</button></form></details></td></tr><?php endforeach; ?>
      <?php if(!$fixedPaymentRowsPage): ?><tr><td colspan="9" class="muted">這個月份目前沒有符合條件的固定開支紀錄。</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($fixedPages > 1): ?><div class="pager"><span>第 <?=h($fixedPage)?> / <?=h($fixedPages)?> 頁，每頁 10 筆；顯示 <?=h(count($fixedPaymentRowsPage))?> / <?=h($fixedTotal)?> 筆</span><?php if($fixedPage > 1): ?><a class="secondary small" href="<?=h($fixedPageUrl($fixedPage - 1))?>">上一頁</a><?php endif; ?><?php if($fixedPage < $fixedPages): ?><a class="secondary small" href="<?=h($fixedPageUrl($fixedPage + 1))?>">下一頁</a><?php endif; ?></div><?php endif; ?>
  </section>


  <section class="ops-card ops-tab" id="finance-fixed-asset">
    <h2>財務系統 / 固定資產</h2>
    <p class="muted">固定資產可串接進貨單據或固定開支付款，自動帶入來源單號、日期、廠商與金額；建檔後由系統計算累計折舊、帳面價值與本月折舊。</p>
    <?php
      $fixedAssetCategories = ['資訊設備','監視 / 網路設備','辦公設備','工具 / 儀器','裝潢 / 附屬設備','交通設備','其他'];
      $fixedAssetSources = [];
      foreach ($stockMovements as $movement) {
          if (($movement['type'] ?? '') !== '進貨') continue;
          $sourceId = trim((string)($movement['id'] ?? ''));
          if ($sourceId === '') continue;
          $fixedAssetSources[] = [
              'key' => 'stock:' . $sourceId,
              'type' => '進貨單據',
              'no' => $movement['doc_no'] ?? $sourceId,
              'name' => $movement['product_title'] ?? '',
              'date' => substr((string)($movement['created_at'] ?? ''), 0, 10),
              'supplier' => $movement['supplier_name'] ?? '',
              'amount' => max(0, (float)($movement['qty'] ?? 0) * (float)($movement['unit_cost'] ?? 0)),
          ];
      }
      foreach ($fixedExpensePayments as $payment) {
          if (in_array(($payment['status'] ?? ''), ['取消','免付'], true)) continue;
          $sourceId = trim((string)($payment['id'] ?? ''));
          if ($sourceId === '') continue;
          $sourcePaidDate = trim((string)($payment['paid_date'] ?? ''));
          $fixedAssetSources[] = [
              'key' => 'fixed:' . $sourceId,
              'type' => '固定開支付款',
              'no' => $payment['payment_no'] ?? $sourceId,
              'name' => $payment['expense_name'] ?? '',
              'date' => $sourcePaidDate !== '' ? $sourcePaidDate : ($payment['due_date'] ?? ''),
              'supplier' => $payment['supplier_name'] ?? '',
              'amount' => max(0, (float)($payment['amount'] ?? 0)),
          ];
      }
      usort($fixedAssetSources, function($a, $b) { return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')); });
      $fixedAssetSourceKeys = array_column($fixedAssetSources, 'key');
      $assetQ = trim((string)($_GET['asset_q'] ?? ''));
      $assetCategoryFilter = trim((string)($_GET['asset_category'] ?? ''));
      $assetStatusFilter = trim((string)($_GET['asset_status'] ?? ''));
      $fixedAssetRows = array_values(array_filter($fixedAssets, function($asset) use ($assetQ, $assetCategoryFilter, $assetStatusFilter) {
          if ($assetCategoryFilter !== '' && ($asset['asset_category'] ?? '') !== $assetCategoryFilter) return false;
          if ($assetStatusFilter !== '' && ($asset['status'] ?? '') !== $assetStatusFilter) return false;
          $hay = implode(' ', [$asset['asset_no'] ?? '',$asset['asset_name'] ?? '',$asset['asset_category'] ?? '',$asset['brand_model'] ?? '',$asset['serial_no'] ?? '',$asset['source_no'] ?? '',$asset['supplier_name'] ?? '',$asset['department'] ?? '',$asset['location'] ?? '',$asset['custodian'] ?? '',$asset['note'] ?? '']);
          return $assetQ === '' || mb_stripos($hay, $assetQ, 0, 'UTF-8') !== false;
      }));
      usort($fixedAssetRows, function($a, $b) { return strcmp((string)($b['acquisition_date'] ?? ''), (string)($a['acquisition_date'] ?? '')); });
      $fixedAssetPage = max(1, (int)($_GET['asset_page'] ?? 1));
      $fixedAssetLimit = 10;
      $fixedAssetTotal = count($fixedAssetRows);
      $fixedAssetPages = max(1, (int)ceil($fixedAssetTotal / $fixedAssetLimit));
      $fixedAssetPage = min($fixedAssetPage, $fixedAssetPages);
      $fixedAssetRowsPage = array_slice($fixedAssetRows, ($fixedAssetPage - 1) * $fixedAssetLimit, $fixedAssetLimit);
      $fixedAssetPageUrl = function($page) use ($assetQ, $assetCategoryFilter, $assetStatusFilter) {
          return 'operations.php?' . http_build_query(['asset_q'=>$assetQ,'asset_category'=>$assetCategoryFilter,'asset_status'=>$assetStatusFilter,'asset_page'=>$page]) . '#finance-fixed-asset';
      };
      $fixedAssetAttentionCount = count(array_filter($fixedAssets, function($asset) { return in_array(($asset['status'] ?? ''), ['維修中','報廢','遺失'], true); }));
    ?>
    <div class="metric-grid">
      <div class="metric"><span>固定資產總數</span><strong><?=h(count($fixedAssets))?> 筆</strong></div>
      <div class="metric"><span>取得成本</span><strong><?=money($fixedAssetTotalCost)?></strong></div>
      <div class="metric"><span>累計折舊</span><strong><?=money($fixedAssetAccumulatedDepreciation)?></strong><small>本月 <?=money($fixedAssetMonthDepreciation)?></small></div>
      <div class="metric"><span>目前帳面價值</span><strong><?=money($fixedAssetBookValue)?></strong><small>維修 / 報廢 <?=h($fixedAssetAttentionCount)?> 筆</small></div>
    </div>

    <h3>新增固定資產</h3>
    <form method="post" class="product-form" id="fixedAssetCreateForm">
      <input type="hidden" name="action" value="save_fixed_asset">
      <label class="wide">來源單據<select name="source_document_key" id="fixedAssetSourceSelect"><option value="">手動建檔</option><?php foreach($fixedAssetSources as $source): ?><option value="<?=h($source['key'])?>" data-name="<?=h($source['name'])?>" data-date="<?=h($source['date'])?>" data-supplier="<?=h($source['supplier'])?>" data-amount="<?=h($source['amount'])?>"><?=h($source['type'] . '｜' . $source['no'] . '｜' . $source['date'] . '｜' . $source['name'] . '｜' . money($source['amount']))?></option><?php endforeach; ?></select></label>
      <label>資產編號<input name="asset_no" placeholder="留空自動產生 FA-日期-流水號"></label>
      <label>資產名稱<input name="asset_name" id="fixedAssetName" required placeholder="例如 NAS 主機"></label>
      <label>資產類別<select name="asset_category"><?php foreach($fixedAssetCategories as $value): ?><option><?=h($value)?></option><?php endforeach; ?></select></label>
      <label>品牌 / 型號<input name="brand_model"></label>
      <label>序號<input name="serial_no"></label>
      <label>數量<input name="quantity" type="number" min="1" value="1"></label>
      <label>取得日期<input name="acquisition_date" id="fixedAssetDate" type="date" value="<?=h(date('Y-m-d'))?>"></label>
      <label>取得成本<input name="acquisition_cost" id="fixedAssetCost" type="number" min="0" step="1" value="0"></label>
      <label>廠商<select name="supplier_id"><option value="">依來源 / 手動輸入</option><?php foreach($suppliers as $supplier): ?><option value="<?=h($supplier['id'] ?? '')?>"><?=h($supplier['name'] ?? '')?></option><?php endforeach; ?></select></label>
      <label>手動廠商名稱<input name="supplier_name" id="fixedAssetSupplier"></label>
      <label>發票 / 憑證號<input name="invoice_no"></label>
      <label>部門<select name="department"><option value="">未指定</option><?php foreach($departmentOptions as $value): ?><option><?=h($value)?></option><?php endforeach; ?></select></label>
      <label>放置位置<input name="location" list="fixedAssetLocationOptions" placeholder="倉庫 / 辦公室 / 樓層"></label>
      <label>保管人<input name="custodian"></label>
      <label>狀態<select name="status"><?php foreach(['使用中','閒置','維修中','遺失','報廢','出售'] as $value): ?><option><?=h($value)?></option><?php endforeach; ?></select></label>
      <label>折舊方式<select name="depreciation_method"><option>直線法</option><option>不折舊</option></select></label>
      <label>使用年限<select name="useful_life_months"><option value="36">3 年</option><option value="60" selected>5 年</option><option value="84">7 年</option><option value="120">10 年</option><option value="180">15 年</option></select></label>
      <label>殘值<input name="residual_value" type="number" min="0" step="1" value="0"></label>
      <label>開始折舊日<input name="depreciation_start_date" type="date"></label>
      <label>保固到期日<input name="warranty_end_date" type="date"></label>
      <label>報廢 / 出售日<input name="disposed_date" type="date"></label>
      <label class="wide">備註<input name="note" placeholder="規格、保固、盤點或維修說明"></label>
      <button class="primary">建立固定資產卡</button>
    </form>
    <datalist id="fixedAssetLocationOptions"><?php foreach($warehouseOptions as $value): ?><option value="<?=h($value)?>"><?php endforeach; ?></datalist>

    <form method="get" action="operations.php#finance-fixed-asset" class="inline-actions fixed-asset-filter">
      <label>搜尋資產<input name="asset_q" value="<?=h($assetQ)?>" placeholder="編號 / 名稱 / 型號 / 序號 / 保管人"></label>
      <label>類別<select name="asset_category"><option value="">全部</option><?php foreach($fixedAssetCategories as $value): ?><option value="<?=h($value)?>" <?=$assetCategoryFilter===$value?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label>
      <label>狀態<select name="asset_status"><option value="">全部</option><?php foreach(['使用中','閒置','維修中','遺失','報廢','出售'] as $value): ?><option value="<?=h($value)?>" <?=$assetStatusFilter===$value?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label>
      <button class="secondary">查詢</button><a class="secondary-link" href="operations.php#finance-fixed-asset">清除</a>
    </form>

    <div class="table-wrap"><table class="fixed-asset-table"><thead><tr><th>資產編號</th><th>資產名稱 / 序號</th><th>類別</th><th>來源單據</th><th>取得日期</th><th>取得成本</th><th>累計折舊</th><th>帳面價值</th><th>位置 / 保管人</th><th>狀態</th><th>操作</th></tr></thead><tbody>
      <?php foreach($fixedAssetRowsPage as $asset): $assetSnapshot=fixed_asset_depreciation_snapshot($asset,date('Y-m-d')); ?>
        <tr class="<?=in_array(($asset['status']??''),['維修中','報廢','遺失'],true)?'fixed-asset-attention':''?>"><td><b><?=h($asset['asset_no'] ?? '')?></b></td><td><b><?=h($asset['asset_name'] ?? '')?></b><small><?=h(trim(($asset['brand_model'] ?? '') . ' ' . ($asset['serial_no'] ?? '')))?></small></td><td><?=h($asset['asset_category'] ?? '')?></td><td><?=h($asset['source_no'] ?? '手動建檔')?><small><?=h($asset['supplier_name'] ?? '')?></small></td><td><?=h($asset['acquisition_date'] ?? '')?></td><td><?=money($asset['acquisition_cost'] ?? 0)?></td><td><?=money($assetSnapshot['accumulated'] ?? 0)?><small><?=h($assetSnapshot['months'] ?? 0)?> 個月</small></td><td><b><?=money($assetSnapshot['book_value'] ?? 0)?></b><small>本月 <?=money($assetSnapshot['monthly'] ?? 0)?></small></td><td><?=h($asset['location'] ?? '')?><small><?=h($asset['custodian'] ?? '')?></small></td><td><span class="status-pill"><?=h($asset['status'] ?? '')?></span></td><td><details class="fixed-asset-editor"><summary>編輯</summary>
          <form method="post" class="product-form"><input type="hidden" name="action" value="save_fixed_asset"><input type="hidden" name="fixed_asset_id" value="<?=h($asset['id'] ?? '')?>">
            <label class="wide">來源單據<select name="source_document_key"><option value="">手動建檔</option><?php if(($asset['source_document_key']??'')!=='' && !in_array(($asset['source_document_key']??''),$fixedAssetSourceKeys,true)): ?><option value="<?=h($asset['source_document_key'] ?? '')?>" selected>歷史來源｜<?=h($asset['source_no'] ?? '')?>｜<?=h($asset['supplier_name'] ?? '')?></option><?php endif; ?><?php foreach($fixedAssetSources as $source): ?><option value="<?=h($source['key'])?>" <?=$source['key']===($asset['source_document_key']??'')?'selected':''?>><?=h($source['type'] . '｜' . $source['no'] . '｜' . $source['name'] . '｜' . money($source['amount']))?></option><?php endforeach; ?></select></label>
            <label>資產編號<input name="asset_no" value="<?=h($asset['asset_no'] ?? '')?>"></label><label>資產名稱<input name="asset_name" value="<?=h($asset['asset_name'] ?? '')?>" required></label><label>類別<select name="asset_category"><?php foreach($fixedAssetCategories as $value): ?><option <?=$value===($asset['asset_category']??'')?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label><label>品牌 / 型號<input name="brand_model" value="<?=h($asset['brand_model'] ?? '')?>"></label><label>序號<input name="serial_no" value="<?=h($asset['serial_no'] ?? '')?>"></label><label>數量<input name="quantity" type="number" min="1" value="<?=h($asset['quantity'] ?? 1)?>"></label><label>取得日期<input name="acquisition_date" type="date" value="<?=h($asset['acquisition_date'] ?? '')?>"></label><label>取得成本<input name="acquisition_cost" type="number" min="0" step="1" value="<?=h($asset['acquisition_cost'] ?? 0)?>"></label><label>廠商<select name="supplier_id"><option value="">依來源 / 手動</option><?php foreach($suppliers as $supplier): ?><option value="<?=h($supplier['id'] ?? '')?>" <?=($supplier['id']??'')===($asset['supplier_id']??'')?'selected':''?>><?=h($supplier['name'] ?? '')?></option><?php endforeach; ?></select></label><label>廠商名稱<input name="supplier_name" value="<?=h($asset['supplier_name'] ?? '')?>"></label><label>發票 / 憑證<input name="invoice_no" value="<?=h($asset['invoice_no'] ?? '')?>"></label><label>部門<select name="department"><option value="">未指定</option><?php foreach($departmentOptions as $value): ?><option <?=$value===($asset['department']??'')?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label><label>位置<input name="location" list="fixedAssetLocationOptions" value="<?=h($asset['location'] ?? '')?>"></label><label>保管人<input name="custodian" value="<?=h($asset['custodian'] ?? '')?>"></label><label>狀態<select name="status"><?php foreach(['使用中','閒置','維修中','遺失','報廢','出售'] as $value): ?><option <?=$value===($asset['status']??'')?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label><label>折舊方式<select name="depreciation_method"><?php foreach(['直線法','不折舊'] as $value): ?><option <?=$value===($asset['depreciation_method']??'')?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label><label>使用年限（月）<input name="useful_life_months" type="number" min="1" value="<?=h($asset['useful_life_months'] ?? 60)?>"></label><label>殘值<input name="residual_value" type="number" min="0" value="<?=h($asset['residual_value'] ?? 0)?>"></label><label>開始折舊日<input name="depreciation_start_date" type="date" value="<?=h($asset['depreciation_start_date'] ?? '')?>"></label><label>保固到期日<input name="warranty_end_date" type="date" value="<?=h($asset['warranty_end_date'] ?? '')?>"></label><label>報廢 / 出售日<input name="disposed_date" type="date" value="<?=h($asset['disposed_date'] ?? '')?>"></label><label class="wide">備註<input name="note" value="<?=h($asset['note'] ?? '')?>"></label><button class="primary">儲存資產</button>
          </form><form method="post" class="inline-form" onsubmit="return confirm('確定刪除此固定資產？');"><input type="hidden" name="action" value="delete_fixed_asset"><input type="hidden" name="fixed_asset_id" value="<?=h($asset['id'] ?? '')?>"><button class="danger small">刪除資產</button></form></details></td></tr>
      <?php endforeach; ?>
      <?php if(!$fixedAssetRowsPage): ?><tr><td colspan="11" class="muted">目前沒有符合條件的固定資產。</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($fixedAssetPages > 1): ?><div class="pager"><span>第 <?=h($fixedAssetPage)?> / <?=h($fixedAssetPages)?> 頁，每頁 10 筆；顯示 <?=h(count($fixedAssetRowsPage))?> / <?=h($fixedAssetTotal)?> 筆</span><?php if($fixedAssetPage > 1): ?><a class="secondary small" href="<?=h($fixedAssetPageUrl($fixedAssetPage - 1))?>">上一頁</a><?php endif; ?><?php if($fixedAssetPage < $fixedAssetPages): ?><a class="secondary small" href="<?=h($fixedAssetPageUrl($fixedAssetPage + 1))?>">下一頁</a><?php endif; ?></div><?php endif; ?>
    <script>
    (function(){
      var source=document.getElementById('fixedAssetSourceSelect');
      if(!source) return;
      source.addEventListener('change',function(){
        var option=source.options[source.selectedIndex];
        var name=document.getElementById('fixedAssetName');
        var date=document.getElementById('fixedAssetDate');
        var cost=document.getElementById('fixedAssetCost');
        var supplier=document.getElementById('fixedAssetSupplier');
        if(name && !name.value) name.value=option.dataset.name||'';
        if(date && option.dataset.date) date.value=option.dataset.date;
        if(cost && option.dataset.amount) cost.value=option.dataset.amount;
        if(supplier && !supplier.value) supplier.value=option.dataset.supplier||'';
      });
    })();
    </script>
  </section>

  <section class="ops-card ops-tab" id="finance-mobile-asset">
    <h2>財務系統 / 移動資產</h2>
    <p class="muted">移動資產直接引用固定資產，不重複計算資產價值。領用、借出、歸還、調撥、送修、修復、遺失與報廢會留下獨立流水，並同步固定資產的位置、保管人與狀態。</p>
    <?php
      $linkedFixedAssetIds = array_values(array_filter(array_map(function($asset){ return $asset['fixed_asset_id'] ?? ''; }, $mobileAssets)));
      $mobileAssetFixedOptions = array_values(array_filter($fixedAssets, function($asset) {
          return !in_array(($asset['status'] ?? ''), ['報廢','出售'], true);
      }));
      $mobileAssetQ = trim((string)($_GET['mobile_asset_q'] ?? ''));
      $mobileAssetStatus = trim((string)($_GET['mobile_asset_status'] ?? ''));
      $mobileAssetRows = array_values(array_filter($mobileAssets, function($asset) use ($mobileAssetQ, $mobileAssetStatus) {
          if ($mobileAssetStatus !== '' && ($asset['status'] ?? '') !== $mobileAssetStatus) return false;
          $hay = implode(' ', [$asset['mobile_asset_no'] ?? '',$asset['fixed_asset_no'] ?? '',$asset['asset_name'] ?? '',$asset['asset_category'] ?? '',$asset['brand_model'] ?? '',$asset['serial_no'] ?? '',$asset['asset_tag'] ?? '',$asset['department'] ?? '',$asset['current_location'] ?? '',$asset['current_holder'] ?? '',$asset['note'] ?? '']);
          return $mobileAssetQ === '' || mb_stripos($hay, $mobileAssetQ, 0, 'UTF-8') !== false;
      }));
      usort($mobileAssetRows, function($a,$b){ return strcmp((string)($b['updated_at']??''),(string)($a['updated_at']??'')); });
      $mobileAssetPage = max(1,(int)($_GET['mobile_asset_page']??1));
      $mobileAssetLimit = 10;
      $mobileAssetTotal = count($mobileAssetRows);
      $mobileAssetPages = max(1,(int)ceil($mobileAssetTotal/$mobileAssetLimit));
      $mobileAssetPage = min($mobileAssetPage,$mobileAssetPages);
      $mobileAssetRowsPage = array_slice($mobileAssetRows,($mobileAssetPage-1)*$mobileAssetLimit,$mobileAssetLimit);
      $mobileMovementQ = trim((string)($_GET['mobile_movement_q'] ?? ''));
      $mobileMovementType = trim((string)($_GET['mobile_movement_type'] ?? ''));
      $mobileMovementRows = array_values(array_filter($mobileAssetMovements, function($movement) use ($mobileMovementQ,$mobileMovementType) {
          if ($mobileMovementType !== '' && ($movement['movement_type'] ?? '') !== $mobileMovementType) return false;
          $hay = implode(' ', [$movement['movement_no']??'',$movement['mobile_asset_no']??'',$movement['fixed_asset_no']??'',$movement['asset_name']??'',$movement['from_location']??'',$movement['to_location']??'',$movement['from_holder']??'',$movement['to_holder']??'',$movement['operator']??'',$movement['note']??'']);
          return $mobileMovementQ === '' || mb_stripos($hay,$mobileMovementQ,0,'UTF-8') !== false;
      }));
      usort($mobileMovementRows,function($a,$b){ return strcmp((string)($b['movement_date']??'').(string)($b['movement_no']??''),(string)($a['movement_date']??'').(string)($a['movement_no']??'')); });
      $mobileMovementPage=max(1,(int)($_GET['mobile_movement_page']??1));
      $mobileMovementLimit=10;
      $mobileMovementTotal=count($mobileMovementRows);
      $mobileMovementPages=max(1,(int)ceil($mobileMovementTotal/$mobileMovementLimit));
      $mobileMovementPage=min($mobileMovementPage,$mobileMovementPages);
      $mobileMovementRowsPage=array_slice($mobileMovementRows,($mobileMovementPage-1)*$mobileMovementLimit,$mobileMovementLimit);
      $mobileBorrowedCount=count(array_filter($mobileAssets,function($asset){return ($asset['status']??'')==='借出中';}));
      $mobileAvailableCount=count(array_filter($mobileAssets,function($asset){return in_array(($asset['status']??''),['可使用','使用中'],true);}));
      $mobileOverdueCount=count(array_filter($mobileAssets,function($asset){return ($asset['status']??'')==='借出中' && trim((string)($asset['expected_return_date']??''))!=='' && ($asset['expected_return_date']??'')<date('Y-m-d');}));
      $mobileAssetPageUrl=function($page) use($mobileAssetQ,$mobileAssetStatus,$mobileMovementQ,$mobileMovementType,$mobileMovementPage){return 'operations.php?'.http_build_query(['mobile_asset_q'=>$mobileAssetQ,'mobile_asset_status'=>$mobileAssetStatus,'mobile_asset_page'=>$page,'mobile_movement_q'=>$mobileMovementQ,'mobile_movement_type'=>$mobileMovementType,'mobile_movement_page'=>$mobileMovementPage]).'#finance-mobile-asset';};
      $mobileMovementPageUrl=function($page) use($mobileAssetQ,$mobileAssetStatus,$mobileAssetPage,$mobileMovementQ,$mobileMovementType){return 'operations.php?'.http_build_query(['mobile_asset_q'=>$mobileAssetQ,'mobile_asset_status'=>$mobileAssetStatus,'mobile_asset_page'=>$mobileAssetPage,'mobile_movement_q'=>$mobileMovementQ,'mobile_movement_type'=>$mobileMovementType,'mobile_movement_page'=>$page]).'#finance-mobile-asset';};
    ?>
    <div class="metric-grid">
      <div class="metric"><span>移動資產總數</span><strong><?=h(count($mobileAssets))?> 筆</strong></div>
      <div class="metric"><span>借出中</span><strong><?=h($mobileBorrowedCount)?> 筆</strong></div>
      <div class="metric"><span>可使用 / 使用中</span><strong><?=h($mobileAvailableCount)?> 筆</strong></div>
      <div class="metric"><span>逾期未還</span><strong class="<?=$mobileOverdueCount>0?'danger-text':''?>"><?=h($mobileOverdueCount)?> 筆</strong></div>
    </div>

    <div class="mobile-asset-workbench">
      <div class="mobile-asset-panel"><h3>建立移動資產卡</h3><form method="post" class="product-form" id="mobileAssetCreateForm"><input type="hidden" name="action" value="save_mobile_asset">
        <label class="wide">固定資產<select name="fixed_asset_id" id="mobileAssetFixedSelect" required><option value="">請選擇尚未建立移動卡的固定資產</option><?php foreach($mobileAssetFixedOptions as $asset): if(in_array(($asset['id']??''),$linkedFixedAssetIds,true)) continue; ?><option value="<?=h($asset['id']??'')?>" data-department="<?=h($asset['department']??'')?>" data-location="<?=h($asset['location']??'')?>" data-holder="<?=h($asset['custodian']??'')?>"><?=h(($asset['asset_no']??'').'｜'.($asset['asset_name']??'').'｜'.($asset['brand_model']??''))?></option><?php endforeach; ?></select></label>
        <label>移動資產編號<input name="mobile_asset_no" placeholder="留空自動產生"></label><label>資產標籤 / 條碼<input name="asset_tag"></label><label>部門<input name="department" id="mobileAssetDepartment"></label><label>固定歸還位置<input name="home_location" id="mobileAssetHomeLocation" list="fixedAssetLocationOptions"></label><label>目前位置<input name="current_location" id="mobileAssetCurrentLocation" list="fixedAssetLocationOptions"></label><label>目前保管人<input name="current_holder" id="mobileAssetCurrentHolder"></label><label>初始狀態<select name="status"><?php foreach(['可使用','使用中','借出中','維修中'] as $value): ?><option><?=h($value)?></option><?php endforeach; ?></select></label><label>領用 / 借出日期<input name="borrowed_at" type="date"></label><label>預計歸還<input name="expected_return_date" type="date"></label><label class="wide">備註<input name="note"></label><button class="primary">建立移動資產卡</button>
      </form></div>
      <div class="mobile-asset-panel"><h3>建立流動紀錄</h3><form method="post" class="product-form"><input type="hidden" name="action" value="save_mobile_asset_movement">
        <label class="wide">移動資產<select name="mobile_asset_id" required><option value="">請選擇</option><?php foreach($mobileAssets as $asset): ?><option value="<?=h($asset['id']??'')?>"><?=h(($asset['mobile_asset_no']??'').'｜'.($asset['asset_name']??'').'｜目前：'.($asset['current_holder']??'').' / '.($asset['current_location']??''))?></option><?php endforeach; ?></select></label><label>異動類型<select name="movement_type"><?php foreach(['領用','借出','歸還','調撥','送修','修復','遺失','報廢'] as $value): ?><option><?=h($value)?></option><?php endforeach; ?></select></label><label>異動日期<input name="movement_date" type="date" value="<?=h(date('Y-m-d'))?>"></label><label>新位置<input name="to_location" list="fixedAssetLocationOptions" placeholder="歸還可留空回固定位置"></label><label>新保管人 / 借用人<input name="to_holder"></label><label>預計歸還日期<input name="expected_return_date" type="date"></label><label class="wide">原因 / 說明<input name="note" required placeholder="領用用途、借出原因、送修廠商或調撥說明"></label><button class="primary">建立異動並同步固定資產</button>
      </form></div>
    </div>

    <h3>目前移動資產</h3>
    <form method="get" action="operations.php#finance-mobile-asset" class="inline-actions mobile-asset-filter"><label>搜尋<input name="mobile_asset_q" value="<?=h($mobileAssetQ)?>" placeholder="編號 / 名稱 / 標籤 / 人員 / 位置"></label><label>狀態<select name="mobile_asset_status"><option value="">全部</option><?php foreach(['可使用','使用中','借出中','維修中','遺失','報廢'] as $value): ?><option value="<?=h($value)?>" <?=$mobileAssetStatus===$value?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label><input type="hidden" name="mobile_movement_q" value="<?=h($mobileMovementQ)?>"><input type="hidden" name="mobile_movement_type" value="<?=h($mobileMovementType)?>"><button class="secondary">查詢</button><a class="secondary-link" href="operations.php#finance-mobile-asset">清除</a></form>
    <div class="table-wrap"><table class="mobile-asset-table"><thead><tr><th>移動資產編號</th><th>固定資產</th><th>資產名稱 / 標籤</th><th>目前位置</th><th>保管 / 借用人</th><th>借出日期</th><th>預計歸還</th><th>狀態</th><th>最後異動</th><th>操作</th></tr></thead><tbody>
      <?php foreach($mobileAssetRowsPage as $asset): $isOverdue=($asset['status']??'')==='借出中' && trim((string)($asset['expected_return_date']??''))!=='' && ($asset['expected_return_date']??'')<date('Y-m-d'); ?><tr class="<?=$isOverdue?'mobile-asset-overdue':(in_array(($asset['status']??''),['維修中','遺失','報廢'],true)?'mobile-asset-attention':'')?>"><td><b><?=h($asset['mobile_asset_no']??'')?></b></td><td><?=h($asset['fixed_asset_no']??'')?></td><td><b><?=h($asset['asset_name']??'')?></b><small><?=h(trim(($asset['asset_tag']??'').' '.($asset['serial_no']??'')))?></small></td><td><?=h($asset['current_location']??'')?><small>歸還：<?=h($asset['home_location']??'')?></small></td><td><?=h($asset['current_holder']??'')?></td><td><?=h($asset['borrowed_at']??'')?></td><td class="<?=$isOverdue?'danger-text':''?>"><?=h($asset['expected_return_date']??'')?></td><td><span class="status-pill"><?=h($asset['status']??'')?></span></td><td><?=h($asset['last_movement_no']??'')?></td><td><details class="mobile-asset-editor"><summary>編輯</summary><form method="post" class="product-form"><input type="hidden" name="action" value="save_mobile_asset"><input type="hidden" name="mobile_asset_id" value="<?=h($asset['id']??'')?>"><input type="hidden" name="fixed_asset_id" value="<?=h($asset['fixed_asset_id']??'')?>"><label>移動資產編號<input name="mobile_asset_no" value="<?=h($asset['mobile_asset_no']??'')?>"></label><label>資產標籤 / 條碼<input name="asset_tag" value="<?=h($asset['asset_tag']??'')?>"></label><label>部門<input name="department" value="<?=h($asset['department']??'')?>"></label><label>固定歸還位置<input name="home_location" list="fixedAssetLocationOptions" value="<?=h($asset['home_location']??'')?>"></label><label>目前位置<input name="current_location" list="fixedAssetLocationOptions" value="<?=h($asset['current_location']??'')?>"></label><label>目前保管人<input name="current_holder" value="<?=h($asset['current_holder']??'')?>"></label><label>狀態<select name="status"><?php foreach(['可使用','使用中','借出中','維修中','遺失','報廢'] as $value): ?><option <?=$value===($asset['status']??'')?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label><label>借出日期<input name="borrowed_at" type="date" value="<?=h($asset['borrowed_at']??'')?>"></label><label>預計歸還<input name="expected_return_date" type="date" value="<?=h($asset['expected_return_date']??'')?>"></label><label class="wide">備註<input name="note" value="<?=h($asset['note']??'')?>"></label><button class="secondary">更新資產卡</button></form><form method="post" class="inline-form" onsubmit="return confirm('確定刪除此移動資產卡？歷史異動會保留。');"><input type="hidden" name="action" value="delete_mobile_asset"><input type="hidden" name="mobile_asset_id" value="<?=h($asset['id']??'')?>"><button class="danger small">刪除資產卡</button></form></details></td></tr><?php endforeach; ?>
      <?php if(!$mobileAssetRowsPage): ?><tr><td colspan="10" class="muted">目前沒有符合條件的移動資產。</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if($mobileAssetPages>1): ?><div class="pager"><span>第 <?=h($mobileAssetPage)?> / <?=h($mobileAssetPages)?> 頁，每頁 10 筆</span><?php if($mobileAssetPage>1): ?><a class="secondary small" href="<?=h($mobileAssetPageUrl($mobileAssetPage-1))?>">上一頁</a><?php endif; ?><?php if($mobileAssetPage<$mobileAssetPages): ?><a class="secondary small" href="<?=h($mobileAssetPageUrl($mobileAssetPage+1))?>">下一頁</a><?php endif; ?></div><?php endif; ?>

    <h3>流動歷程</h3>
    <form method="get" action="operations.php#finance-mobile-asset" class="inline-actions mobile-asset-filter"><input type="hidden" name="mobile_asset_q" value="<?=h($mobileAssetQ)?>"><input type="hidden" name="mobile_asset_status" value="<?=h($mobileAssetStatus)?>"><label>搜尋紀錄<input name="mobile_movement_q" value="<?=h($mobileMovementQ)?>" placeholder="單號 / 資產 / 人員 / 位置 / 操作人"></label><label>類型<select name="mobile_movement_type"><option value="">全部</option><?php foreach(['領用','借出','歸還','調撥','送修','修復','遺失','報廢'] as $value): ?><option value="<?=h($value)?>" <?=$mobileMovementType===$value?'selected':''?>><?=h($value)?></option><?php endforeach; ?></select></label><button class="secondary">查詢歷程</button></form>
    <div class="table-wrap"><table class="mobile-movement-table"><thead><tr><th>日期</th><th>異動單號</th><th>類型</th><th>資產</th><th>原位置 / 人員</th><th>新位置 / 人員</th><th>預計歸還</th><th>異動後狀態</th><th>說明</th><th>操作人</th></tr></thead><tbody><?php foreach($mobileMovementRowsPage as $movement): ?><tr><td><?=h($movement['movement_date']??'')?></td><td><?=h($movement['movement_no']??'')?></td><td><b><?=h($movement['movement_type']??'')?></b></td><td><?=h($movement['mobile_asset_no']??'')?><small><?=h($movement['asset_name']??'')?></small></td><td><?=h($movement['from_location']??'')?><small><?=h($movement['from_holder']??'')?></small></td><td><?=h($movement['to_location']??'')?><small><?=h($movement['to_holder']??'')?></small></td><td><?=h($movement['expected_return_date']??'')?></td><td><?=h($movement['status_after']??'')?></td><td><?=h($movement['note']??'')?></td><td><?=h($movement['operator']??'')?></td></tr><?php endforeach; ?><?php if(!$mobileMovementRowsPage): ?><tr><td colspan="10" class="muted">目前沒有移動資產異動紀錄。</td></tr><?php endif; ?></tbody></table></div>
    <?php if($mobileMovementPages>1): ?><div class="pager"><span>第 <?=h($mobileMovementPage)?> / <?=h($mobileMovementPages)?> 頁，每頁 10 筆</span><?php if($mobileMovementPage>1): ?><a class="secondary small" href="<?=h($mobileMovementPageUrl($mobileMovementPage-1))?>">上一頁</a><?php endif; ?><?php if($mobileMovementPage<$mobileMovementPages): ?><a class="secondary small" href="<?=h($mobileMovementPageUrl($mobileMovementPage+1))?>">下一頁</a><?php endif; ?></div><?php endif; ?>
    <script>(function(){var select=document.getElementById('mobileAssetFixedSelect');if(!select)return;select.addEventListener('change',function(){var option=select.options[select.selectedIndex];var department=document.getElementById('mobileAssetDepartment');var home=document.getElementById('mobileAssetHomeLocation');var current=document.getElementById('mobileAssetCurrentLocation');var holder=document.getElementById('mobileAssetCurrentHolder');if(department)department.value=option.dataset.department||'';if(home)home.value=option.dataset.location||'';if(current)current.value=option.dataset.location||'';if(holder)holder.value=option.dataset.holder||'';});})();</script>
  </section>

</main>
</div>
</div>
<script src="ops-search-panels.js?v=20260819-supplier-source-1" charset="UTF-8"></script>
<script src="assets/app.js"></script>

<script>
const colorModules = <?php echo json_encode(array_values($colorModules), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const productSpecs = <?php echo json_encode(array_values($productSpecs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const purchaseSourceOptions = <?php echo json_encode(array_values($purchaseSourceOptions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const categoryTypeCodeMap = <?php echo json_encode(category_type_code_map(), json_flags(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>;
const sharedCategoryTypes = <?php echo json_encode($sharedCategoryTypes, json_flags(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>;
const productCategoryTypesByGroup = <?php echo json_encode(product_category_types_by_group(), json_flags(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>;
const productCategoryRules = <?php echo json_encode($productCategoryRulesForJs, json_flags(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>;
const stockCategoryRules = <?php echo json_encode($stockCategoryRulesForJs, json_flags(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>;
const salesCustomerDirectory = <?php echo json_encode(member_contact_directory($members), json_flags(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>;
const productColorCodeMap = <?php echo json_encode($barcodeColorCodes, json_flags(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>;
const productSizeCodeMap = <?php echo json_encode($barcodeSizeCodes, json_flags(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>;
const departmentWarehouseMap = <?php echo json_encode(department_warehouse_map(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const warehouseTreeData = <?php echo json_encode(warehouse_tree($warehouses), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const warehouseFallbackOptions = <?php echo json_encode(array_values($warehouseOptions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const shelfFallbackOptions = <?php echo json_encode(array_values($shelfOptions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const layerFallbackOptions = <?php echo json_encode(array_values($layerOptions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function prepareEmbeddedQuotationFrame(frame) {
  const status = document.getElementById('embeddedQuotationStatus');
  try {
    const doc = frame.contentDocument;
    const win = frame.contentWindow;
    if (!doc || !win) return;
    if (!doc.getElementById('baohui-quotation-embed-style')) {
      const style = doc.createElement('style');
      style.id = 'baohui-quotation-embed-style';
      style.textContent = `
        .sidebar,.top-bar,.mobile-sidebar-backdrop,.mobile-back-button,.mobile-menu-btn{display:none!important}
        .main-content{margin-left:0!important;padding:16px!important;min-height:100vh!important}
        #mainSystem{min-height:100vh!important}
        #page-quotationManager{max-width:none!important}
        body{background:#f1f5f9!important}
      `;
      doc.head.appendChild(style);
    }
    const openQuotationPage = () => {
      try {
        if (typeof win.goPage === 'function') win.goPage('quotationManager');
        if (typeof win.renderQuotationList === 'function') win.renderQuotationList();
        if (status) status.textContent = '總部估價單已載入，可直接在這裡操作。';
      } catch (error) {
        if (status) status.textContent = '估價單功能載入中，請稍候。';
      }
    };
    openQuotationPage();
    window.setTimeout(openQuotationPage, 350);
    window.setTimeout(openQuotationPage, 1000);
  } catch (error) {
    if (status) status.textContent = '無法載入估價單，請確認總部登入狀態後重新載入。';
  }
}
function loadEmbeddedQuotation(forceReload) {
  const frame = document.getElementById('embeddedQuotationFrame');
  const status = document.getElementById('embeddedQuotationStatus');
  if (!frame) return;
  const source = frame.dataset.src || '../admin.php#quotationManager';
  if (forceReload || !frame.getAttribute('src')) {
    if (status) status.textContent = '正在載入總部估價單資料...';
    frame.onload = () => prepareEmbeddedQuotationFrame(frame);
    frame.setAttribute('src', forceReload ? `${source.split('#')[0]}?quote_embed_refresh=${Date.now()}#quotationManager` : source);
    return;
  }
  prepareEmbeddedQuotationFrame(frame);
}
window.loadEmbeddedQuotation = loadEmbeddedQuotation;

function uniqueClean(values) {
  return Array.from(new Set((values || []).map((value) => String(value || '').trim()).filter(Boolean)));
}
function setOptions(select, values, placeholder, currentValue) {
  if (!select) return;
  const current = String(currentValue ?? select.dataset.current ?? select.value ?? '').trim();
  const list = uniqueClean(values);
  if (current && !list.includes(current)) list.unshift(current);
  select.innerHTML = '';
  const empty = document.createElement('option');
  empty.value = '';
  empty.textContent = placeholder;
  select.appendChild(empty);
  list.forEach((value) => {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = value;
    select.appendChild(option);
  });
  select.value = current;
}
function setupStockCategoryCascade() {
  const groupSelect = document.getElementById('stockCategoryGroupSelect');
  const typeSelect = document.getElementById('stockCategoryTypeSelect');
  const brandSelect = document.getElementById('stockCategoryBrandSelect');
  const specSelect = document.getElementById('stockCategorySpecSelect');
  if (!groupSelect || !typeSelect || !brandSelect || !specSelect) return;
  const rules = Array.isArray(stockCategoryRules) ? stockCategoryRules : [];
  const initial = {
    group: groupSelect.dataset.current || '',
    type: typeSelect.dataset.current || '',
    brand: brandSelect.dataset.current || '',
    spec: specSelect.dataset.current || ''
  };
  const rulesFor = (group, type, brand) => rules.filter((rule) => {
    if (group && String(rule.group || '').trim() !== group) return false;
    if (type && String(rule.type || '').trim() !== type) return false;
    if (brand && String(rule.brand || '').trim() !== brand) return false;
    return true;
  });
  const refreshSpecs = (current) => {
    setOptions(specSelect, rulesFor(groupSelect.value, typeSelect.value, brandSelect.value).map((rule) => rule.spec), '全部細分類', current ?? '');
  };
  const refreshBrands = (currentBrand, currentSpec) => {
    setOptions(brandSelect, rulesFor(groupSelect.value, typeSelect.value, '').map((rule) => rule.brand), '全部廠牌', currentBrand ?? '');
    refreshSpecs(currentSpec ?? '');
  };
  const refreshTypes = (currentType, currentBrand, currentSpec) => {
    setOptions(typeSelect, rulesFor(groupSelect.value, '', '').map((rule) => rule.type), '全部類別', currentType ?? '');
    refreshBrands(currentBrand ?? '', currentSpec ?? '');
  };
  groupSelect.addEventListener('change', () => refreshTypes('', '', ''));
  typeSelect.addEventListener('change', () => refreshBrands('', ''));
  brandSelect.addEventListener('change', () => refreshSpecs(''));
  setOptions(groupSelect, rules.map((rule) => rule.group), '全部群組', initial.group);
  refreshTypes(initial.type, initial.brand, initial.spec);
}
setupStockCategoryCascade();
function warehouseNodeByName(name, department) {
  const wantedName = String(name || '');
  const wantedDept = String(department || '');
  const exact = (warehouseTreeData || []).find((item) => String(item.name || '') === wantedName && (!wantedDept || String(item.department || '') === wantedDept));
  if (exact) return exact;
  return (warehouseTreeData || []).find((item) => String(item.name || '') === wantedName) || null;
}
function shelfNamesForWarehouse(warehouse, department) {
  const node = warehouseNodeByName(warehouse, department);
  if (!node) return shelfFallbackOptions;
  return Object.keys(node.shelves || {});
}
function layerNamesForLocation(warehouse, shelf, department) {
  return ['上層', '下層'];
}
function defaultWarehouseForDepartment(dept) {
  const name = String(dept || '').trim();
  const list = departmentWarehouseMap && departmentWarehouseMap[name];
  return (list && list[0]) ? list[0] : '電腦倉';
}
function bindWarehouseSelects(warehouseId, shelfId, locationId, placeholders, departmentId) {
  const warehouse = document.getElementById(warehouseId);
  const shelf = document.getElementById(shelfId);
  const location = document.getElementById(locationId);
  const department = departmentId ? document.getElementById(departmentId) : null;
  if (!warehouse || !shelf || !location) return null;
  const labels = Object.assign({
    warehouse: '請選擇倉別',
    shelf: '請先選倉別',
    location: '還沒放上去'
  }, placeholders || {});
  const keepUnchanged = labels.shelf === '不變更原貨架' || labels.location === '不變更原倉位';
  function currentDepartment() {
    return department ? String(department.value || '').trim() : '';
  }
  function warehouseNamesForDepartment() {
    const dept = currentDepartment();
    return dept && departmentWarehouseMap && departmentWarehouseMap[dept] ? departmentWarehouseMap[dept] : warehouseFallbackOptions;
  }
  function shelfPlaceholder(hasWarehouse) {
    if (keepUnchanged) return labels.shelf;
    return hasWarehouse ? '還沒放上去' : labels.shelf;
  }
  function locationPlaceholder(hasShelf) {
    if (keepUnchanged) return labels.location;
    return hasShelf ? '請選上層或下層' : '還沒放上去';
  }
  function refreshLocations(nextLocation) {
    const hasShelf = !!String(shelf.value || '').trim();
    setOptions(location, layerNamesForLocation(), locationPlaceholder(hasShelf), hasShelf || keepUnchanged ? (nextLocation ?? location.value) : '');
    if (!keepUnchanged) location.disabled = !hasShelf;
  }
  function refreshShelves(nextShelf) {
    const hasWarehouse = !!String(warehouse.value || '').trim();
    setOptions(shelf, hasWarehouse || keepUnchanged ? shelfNamesForWarehouse(warehouse.value, currentDepartment()) : [], shelfPlaceholder(hasWarehouse), nextShelf ?? shelf.value);
    refreshLocations(location.value);
  }
  function setValues(values) {
    const next = values || {};
    setOptions(warehouse, warehouseNamesForDepartment(), labels.warehouse, next.warehouse ?? warehouse.value);
    refreshShelves(next.shelf ?? '');
    refreshLocations(next.location ?? '');
  }
  warehouse.addEventListener('change', () => {
    shelf.dataset.current = '';
    location.dataset.current = '';
    refreshShelves('');
  });
  if (department) {
    department.addEventListener('change', () => {
      warehouse.dataset.current = '';
      shelf.dataset.current = '';
      location.dataset.current = '';
      setValues({ warehouse: keepUnchanged ? '' : defaultWarehouseForDepartment(department.value), shelf: '', location: '' });
    });
  }
  shelf.addEventListener('change', () => {
    location.dataset.current = '';
    refreshLocations(keepUnchanged ? location.value : '');
  });
  setValues({
    warehouse: warehouse.dataset.current || '',
    shelf: shelf.dataset.current || '',
    location: location.dataset.current || ''
  });
  return {warehouse, shelf, location, setValues};
}
function bindWarehouseSelectElements(warehouse, shelf, location, placeholders, departmentValue) {
  if (!warehouse || !shelf || !location) return null;
  const labels = Object.assign({
    warehouse: '請選擇倉別',
    shelf: '請先選倉別',
    location: '還沒放上去'
  }, placeholders || {});
  const keepUnchanged = labels.shelf === '不變更原貨架' || labels.location === '不變更原倉位';
  const currentDepartment = () => String(departmentValue || '').trim();
  const warehouseNamesForDepartment = () => {
    const dept = currentDepartment();
    return dept && departmentWarehouseMap && departmentWarehouseMap[dept] ? departmentWarehouseMap[dept] : warehouseFallbackOptions;
  };
  function shelfPlaceholder(hasWarehouse) {
    if (keepUnchanged) return labels.shelf;
    return hasWarehouse ? '還沒放上去' : labels.shelf;
  }
  function locationPlaceholder(hasShelf) {
    if (keepUnchanged) return labels.location;
    return hasShelf ? '請選上層或下層' : '還沒放上去';
  }
  function refreshLocations(nextLocation) {
    const hasShelf = !!String(shelf.value || '').trim();
    setOptions(location, layerNamesForLocation(), locationPlaceholder(hasShelf), hasShelf || keepUnchanged ? (nextLocation ?? location.value) : '');
    if (!keepUnchanged) location.disabled = !hasShelf;
  }
  function refreshShelves(nextShelf, nextLocation) {
    const hasWarehouse = !!String(warehouse.value || '').trim();
    setOptions(shelf, hasWarehouse || keepUnchanged ? shelfNamesForWarehouse(warehouse.value, currentDepartment()) : [], shelfPlaceholder(hasWarehouse), nextShelf ?? shelf.value);
    refreshLocations(nextLocation ?? location.value);
  }
  function setValues(values) {
    const next = values || {};
    setOptions(warehouse, warehouseNamesForDepartment(), labels.warehouse, next.warehouse ?? warehouse.value);
    refreshShelves(next.shelf ?? '', next.location ?? '');
  }
  warehouse.addEventListener('change', () => refreshShelves('', ''));
  shelf.addEventListener('change', () => refreshLocations(keepUnchanged ? location.value : ''));
  setValues({
    warehouse: warehouse.dataset.current || '',
    shelf: shelf.dataset.current || '',
    location: location.dataset.current || ''
  });
  return { warehouse, shelf, location, setValues };
}
const productLocationBinding = bindWarehouseSelects('productWarehouseSelect', 'productShelfSelect', 'productLocationSelect', null, 'productDepartmentSelect');
const productQuickStockLocationBinding = bindWarehouseSelects('productQuickStockWarehouse', 'productQuickStockShelf', 'productQuickStockLocation', {
  warehouse: '請選擇倉別',
  shelf: '請先選倉別',
  location: '還沒放上去'
}, 'productQuickStockDepartment');
const stockLocationBinding = bindWarehouseSelects('stockWarehouseInput', 'stockShelfInput', 'stockLocationInput', {
  warehouse: '不變更原倉庫',
  shelf: '不變更原貨架',
  location: '不變更原倉位'
});
bindWarehouseSelects('stockManageWarehouseSelect', 'stockManageShelfSelect', 'stockManageLocationSelect');
window.setStockLocationFromProduct = function(product) {
  if (!stockLocationBinding || !product) return false;
  stockLocationBinding.setValues({
    warehouse: product.warehouse_name || '',
    shelf: product.shelf_code || '',
    location: product.warehouse_location || ''
  });
  return true;
};
function departmentForWarehouseName(warehouse) {
  const name = String(warehouse || '').trim();
  const preferred = warehouseNodeByName(name, '電腦部門');
  if (preferred && preferred.department) return preferred.department;
  const node = warehouseNodeByName(name);
  return (node && node.department) ? node.department : '電腦部門';
}
function registerShelfInTree(warehouse, shelf, department) {
  const warehouseName = String(warehouse || '').trim();
  const shelfName = String(shelf || '').trim();
  const dept = String(department || '').trim() || departmentForWarehouseName(warehouseName);
  if (!warehouseName || !shelfName) return;
  let node = warehouseNodeByName(warehouseName, dept) || warehouseNodeByName(warehouseName);
  if (!node) {
    node = { department: dept, name: warehouseName, shelves: {} };
    warehouseTreeData.push(node);
  }
  if (!node.shelves || Array.isArray(node.shelves)) {
    const next = {};
    Object.keys(node.shelves || {}).forEach((key) => {
      next[key] = node.shelves[key];
    });
    node.shelves = next;
  }
  if (!node.shelves[shelfName]) node.shelves[shelfName] = { name: shelfName, layers: ['上層', '下層'] };
}
function bindShelfAdd(warehouseId, shelfId, inputId, buttonId, departmentId, binding) {
  const warehouse = document.getElementById(warehouseId);
  const shelf = document.getElementById(shelfId);
  const input = document.getElementById(inputId);
  const button = document.getElementById(buttonId);
  const department = departmentId ? document.getElementById(departmentId) : null;
  if (!warehouse || !shelf || !input || !button) return;
  function addShelf() {
    const warehouseName = String(warehouse.value || '').trim();
    const shelfName = String(input.value || '').replace(/\s+/g, ' ').trim();
    if (!warehouseName) {
      window.alert('請先選倉別，再新增倉架名稱。');
      return;
    }
    if (!shelfName) {
      window.alert('請輸入倉架名稱，例如 A01。');
      input.focus();
      return;
    }
    const dept = String(department && department.value || departmentForWarehouseName(warehouseName)).trim() || '電腦部門';
    button.disabled = true;
    const body = new URLSearchParams({
      action: 'save_warehouse_item',
      ajax: '1',
      warehouse_item_type: 'shelf',
      warehouse_item_department: dept,
      warehouse_item_name: shelfName,
      warehouse_item_warehouse: warehouseName,
      warehouse_item_shelf: shelfName
    });
    fetch('operations.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      credentials: 'same-origin',
      body
    }).then((response) => response.json().catch(() => ({}))).then((payload) => {
      if (Array.isArray(payload.tree)) {
        warehouseTreeData.splice(0, warehouseTreeData.length, ...payload.tree);
      } else {
        registerShelfInTree(warehouseName, shelfName, dept);
      }
      if (binding && typeof binding.setValues === 'function') {
        binding.setValues({
          warehouse: warehouseName,
          shelf: payload.shelf || shelfName,
          location: document.getElementById(shelfId === 'productQuickStockShelf' ? 'productQuickStockLocation' : (shelfId === 'productShelfSelect' ? 'productLocationSelect' : ''))?.value || ''
        });
      } else {
        registerShelfInTree(warehouseName, shelfName, dept);
        setOptions(shelf, shelfNamesForWarehouse(warehouseName, dept), '還沒放上去', payload.shelf || shelfName);
      }
      input.value = '';
      if (payload.ok === false) window.alert(payload.message || '貨架新增失敗');
    }).catch(() => {
      registerShelfInTree(warehouseName, shelfName, dept);
      if (binding && typeof binding.setValues === 'function') {
        binding.setValues({ warehouse: warehouseName, shelf: shelfName, location: '' });
      }
      window.alert('貨架已先帶入這一筆；若重整後消失，請到貨倉管理再新增一次。');
    }).finally(() => {
      button.disabled = false;
    });
  }
  button.addEventListener('click', addShelf);
  input.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    addShelf();
  });
}
bindShelfAdd('productWarehouseSelect', 'productShelfSelect', 'productShelfAddInput', 'productShelfAddBtn', 'productDepartmentSelect', productLocationBinding);
bindShelfAdd('productQuickStockWarehouse', 'productQuickStockShelf', 'productQuickStockShelfAdd', 'productQuickStockShelfAddBtn', 'productQuickStockDepartment', productQuickStockLocationBinding);

function refreshColorSizeOptions() {
  const moduleSelect = document.getElementById('colorModuleSelect');
  const colorSelect = document.getElementById('productColorPairSelect');
  const sizeSelect = document.getElementById('productSizePairSelect');
  if (!moduleSelect || !colorSelect || !sizeSelect) return;
  const module = colorModules.find((item) => String(item.id || '') === moduleSelect.value);
  const normalizeName = (value) => String(value || '').toLowerCase().replace(/[\s/\-_+]/g, '');
  const codeForName = (map, name) => {
    const wanted = normalizeName(name);
    if (!wanted) return '';
    const entries = Object.entries(map || {});
    const exact = entries.find(([, label]) => normalizeName(label) === wanted);
    if (exact) return exact[0];
    const zhExact = entries.find(([, label]) => normalizeName(String(label).split('/')[0]) === wanted);
    if (zhExact) return zhExact[0];
    const prefix = entries.find(([, label]) => {
      const zh = normalizeName(String(label).split('/')[0]);
      return zh.startsWith(wanted) || wanted.startsWith(zh);
    });
    return prefix ? prefix[0] : '';
  };
  const fill = (node, values, map, placeholder) => {
    node.innerHTML = '';
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = module ? placeholder : '請先選顏色尺碼類別';
    node.appendChild(empty);
    uniqueClean(values || []).forEach((value) => {
      const option = document.createElement('option');
      const code = codeForName(map, value);
      option.value = `${code}|${value}`;
      option.textContent = code ? `${code}　${value}` : value;
      node.appendChild(option);
    });
  };
  const useShared = !module || String(module.id || '') === 'cm_clothes_shared';
  fill(colorSelect, useShared && moduleSelect.value === 'cm_clothes_shared' ? Object.values(productColorCodeMap || {}) : (module ? module.colors : []), productColorCodeMap, '請選擇模組顏色');
  fill(sizeSelect, useShared && moduleSelect.value === 'cm_clothes_shared' ? Object.values(productSizeCodeMap || {}) : (module ? module.sizes : []), productSizeCodeMap, '請選擇模組尺寸');
}
document.getElementById('colorModuleSelect')?.addEventListener('change', refreshColorSizeOptions);
refreshColorSizeOptions();
function setupColorModuleAddPanel() {
  const toggle = document.getElementById('toggleAddColorModule');
  const panel = document.getElementById('addColorModulePanel');
  const cancel = document.getElementById('cancelAddColorModule');
  const save = document.getElementById('saveNewColorModule');
  const nameInput = document.getElementById('newColorModuleName');
  const colorsInput = document.getElementById('newColorModuleColors');
  const sizesInput = document.getElementById('newColorModuleSizes');
  const status = document.getElementById('addColorModuleStatus');
  const select = document.getElementById('colorModuleSelect');
  if (!toggle || !panel || !save || !nameInput || !colorsInput || !sizesInput || !select) return;
  const setOpen = (open) => {
    panel.hidden = !open;
    toggle.textContent = open ? '收合新增面板' : '新增模組面板';
  };
  toggle.addEventListener('click', () => setOpen(panel.hidden));
  cancel?.addEventListener('click', () => setOpen(false));
  save.addEventListener('click', async () => {
    const name = String(nameInput.value || '').trim();
    const colors = String(colorsInput.value || '').trim();
    const sizes = String(sizesInput.value || '').trim();
    if (!name || (!colors && !sizes)) {
      if (status) status.textContent = '請填模組名稱，並至少填顏色或尺寸。';
      return;
    }
    save.disabled = true;
    const original = save.textContent;
    save.textContent = '儲存中...';
    if (status) status.textContent = '正在新增模組面板...';
    try {
      const form = new FormData();
      form.append('action', 'save_color_module');
      form.append('ajax', '1');
      form.append('color_module_name', name);
      form.append('color_module_colors', colors);
      form.append('color_module_sizes', sizes);
      const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: form, credentials: 'same-origin' });
      const payload = await response.json();
      if (!payload || !payload.ok || !payload.module) throw new Error(payload && payload.error ? payload.error : '儲存失敗');
      const module = payload.module;
      const existing = colorModules.findIndex((item) => String(item.id || '') === String(module.id || ''));
      if (existing >= 0) colorModules[existing] = Object.assign({}, colorModules[existing], module);
      else colorModules.push(module);
      if (![...select.options].some((option) => option.value === module.id)) {
        const option = document.createElement('option');
        option.value = module.id;
        option.textContent = module.name;
        select.appendChild(option);
      } else {
        const option = [...select.options].find((item) => item.value === module.id);
        if (option) option.textContent = module.name;
      }
      select.value = module.id;
      refreshColorSizeOptions();
      nameInput.value = '';
      colorsInput.value = '';
      sizesInput.value = '';
      setOpen(false);
      if (status) status.textContent = '已新增並套用「' + module.name + '」。下面可直接選顏色／尺寸。';
    } catch (error) {
      if (status) status.textContent = error && error.message ? error.message : '模組儲存失敗，請再試一次。';
    } finally {
      save.disabled = false;
      save.textContent = original;
    }
  });
}
setupColorModuleAddPanel();
function setupProductSpecCombo() {
  const combo = document.getElementById('productSpecCombo');
  const input = document.getElementById('productSpecInput');
  const menu = document.getElementById('productSpecMenu');
  const save = document.getElementById('saveProductSpecOption');
  if (!combo || !input || !menu || !save) return;
  let activeIndex = -1;
  const normalize = (value) => String(value || '').trim().toLowerCase();
  const currentSpecs = () => (Array.isArray(productSpecs) ? productSpecs : []);
  const matchingSpecs = () => {
    const q = normalize(input.value);
    const list = currentSpecs();
    if (!q) return list.slice(0, 40);
    return list.filter((item) => normalize(item).includes(q)).slice(0, 40);
  };
  const hideMenu = () => {
    menu.hidden = true;
    activeIndex = -1;
  };
  const renderMenu = () => {
    const list = matchingSpecs();
    const q = String(input.value || '').trim();
    if (!list.length) {
      menu.innerHTML = q
        ? '<div class="spec-combo-empty">沒有符合的舊規格，可直接打字後按「儲存此規格」</div>'
        : '<div class="spec-combo-empty">還沒有儲存過的規格，打字後按「儲存此規格」</div>';
      menu.hidden = false;
      return;
    }
    menu.innerHTML = list.map((item, index) => '<button type="button" class="spec-combo-option' + (index === activeIndex ? ' is-active' : '') + '" data-spec="' + encodeURIComponent(item) + '">' + escapeHtml(item) + '</button>').join('');
    menu.hidden = false;
  };
  const pickSpec = (value) => {
    input.value = String(value || '').trim();
    hideMenu();
    input.dispatchEvent(new Event('change', { bubbles: true }));
  };
  const rememberSpec = async () => {
    const value = String(input.value || '').trim();
    if (!value) {
      renderMenu();
      return;
    }
    save.disabled = true;
    const original = save.textContent;
    save.textContent = '儲存中...';
    try {
      const form = new FormData();
      form.append('action', 'save_product_spec');
      form.append('ajax', '1');
      form.append('spec', value);
      const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: form, credentials: 'same-origin' });
      const payload = await response.json();
      if (!payload || !payload.ok) throw new Error(payload && payload.error ? payload.error : '儲存失敗');
      if (Array.isArray(payload.specs)) {
        productSpecs.splice(0, productSpecs.length, ...payload.specs);
      } else if (!currentSpecs().some((item) => normalize(item) === normalize(value))) {
        productSpecs.push(value);
      }
      renderMenu();
    } catch (error) {
      alert(error && error.message ? error.message : '規格儲存失敗，請再試一次。');
    } finally {
      save.disabled = false;
      save.textContent = original;
    }
  };
  input.addEventListener('focus', renderMenu);
  input.addEventListener('input', () => {
    activeIndex = -1;
    renderMenu();
  });
  input.addEventListener('keydown', (event) => {
    const options = Array.from(menu.querySelectorAll('.spec-combo-option'));
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      if (menu.hidden) renderMenu();
      activeIndex = Math.min(options.length - 1, activeIndex + 1);
      renderMenu();
      return;
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault();
      activeIndex = Math.max(0, activeIndex - 1);
      renderMenu();
      return;
    }
    if (event.key === 'Enter') {
      event.preventDefault();
      if (activeIndex >= 0 && options[activeIndex]) {
        pickSpec(decodeURIComponent(options[activeIndex].dataset.spec || ''));
        return;
      }
      rememberSpec();
      return;
    }
    if (event.key === 'Escape') hideMenu();
  });
  menu.addEventListener('mousedown', (event) => {
    const option = event.target.closest('.spec-combo-option');
    if (!option) return;
    event.preventDefault();
    pickSpec(decodeURIComponent(option.dataset.spec || ''));
  });
  save.addEventListener('click', rememberSpec);
  input.addEventListener('blur', () => {
    const value = String(input.value || '').trim();
    if (!value) return;
    if (currentSpecs().some((item) => normalize(item) === normalize(value))) return;
    rememberSpec();
  });
  document.addEventListener('click', (event) => {
    if (!combo.contains(event.target)) hideMenu();
  });
}
setupProductSpecCombo();
function setupPurchaseSourceCombo() {
  const combo = document.getElementById('productPurchaseSourceCombo');
  const input = document.getElementById('productPurchaseSource');
  const menu = document.getElementById('productPurchaseSourceMenu');
  const status = document.getElementById('productPurchaseSourceStatus');
  if (!combo || !input || !menu) return;
  let activeIndex = -1;
  let lastSaved = String(input.value || '').trim();
  const normalize = (value) => String(value || '').trim().toLowerCase();
  const currentSources = () => (Array.isArray(purchaseSourceOptions) ? purchaseSourceOptions : []);
  const matchingSources = () => {
    const q = normalize(input.value);
    const list = currentSources();
    if (!q) return list.slice(0, 40);
    return list.filter((item) => normalize(item).includes(q)).slice(0, 40);
  };
  const hideMenu = () => { menu.hidden = true; activeIndex = -1; };
  const setStatus = (text) => { if (status) status.textContent = text; };
  const renderMenu = () => {
    const list = matchingSources();
    const q = String(input.value || '').trim();
    if (!list.length) {
      menu.innerHTML = q
        ? '<div class="spec-combo-empty">沒有符合的廠商，可直接打字；離開欄位會自動存進廠商搜尋分類</div>'
        : '<div class="spec-combo-empty">可選既有廠商，或手打新供應來源</div>';
      menu.hidden = false;
      return;
    }
    menu.innerHTML = list.map((item, index) => '<button type="button" class="spec-combo-option' + (index === activeIndex ? ' is-active' : '') + '" data-source="' + encodeURIComponent(item) + '">' + escapeHtml(item) + '</button>').join('');
    menu.hidden = false;
  };
  const applyCnyIfNeeded = (value) => {
    if (!['拼多多', '豪鴻'].includes(value)) return;
    const currency = document.getElementById('productPurchaseCurrency');
    const rate = document.getElementById('productPurchaseRate');
    if (currency) currency.value = 'CNY';
    if (rate) rate.value = String(<?=json_encode((float)($purchaseCostSettings['rmb_fixed_rate'] ?? 5))?>);
    if (typeof refreshProductPurchaseConversion === 'function') refreshProductPurchaseConversion();
  };
  const syncSupplierSelects = (supplier) => {
    if (!supplier || !supplier.id) return;
    document.querySelectorAll('select[name="stock_supplier_id"], select[name="supplier_id"]').forEach((select) => {
      if ([...select.options].some((option) => option.value === String(supplier.id))) return;
      const option = document.createElement('option');
      option.value = String(supplier.id);
      option.textContent = String(supplier.name || '');
      select.appendChild(option);
    });
    const list = document.getElementById('supplierNameList');
    if (list && ![...list.options].some((option) => option.value === String(supplier.name || ''))) {
      const option = document.createElement('option');
      option.value = String(supplier.name || '');
      list.appendChild(option);
    }
  };
  const addSupplierRow = (supplier, category) => {
    const table = document.getElementById('supplierDirectoryTable');
    const body = table && table.querySelector('tbody');
    if (!body || !supplier) return;
    const empty = body.querySelector('[data-supplier-empty]');
    if (empty) empty.remove();
    if (body.querySelector('[data-supplier-id="' + CSS.escape(String(supplier.id || '')) + '"]')) return;
    const row = document.createElement('tr');
    row.setAttribute('data-supplier-id', String(supplier.id || ''));
    row.setAttribute('data-supplier-name', String(supplier.name || ''));
    row.setAttribute('data-supplier-category', String(category || '產品建檔供應來源'));
    row.setAttribute('data-created-at', String(supplier.created_at || ''));
    row.innerHTML = '<td></td><td>' + escapeHtml(supplier.name || '') + '</td><td>' + escapeHtml(category || '產品建檔供應來源') + '</td><td>' + escapeHtml(supplier.contact || '') + '</td><td>' + escapeHtml(supplier.phone || '') + '</td><td>' + escapeHtml(supplier.tax_id || '') + '</td><td>' + escapeHtml(supplier.address || '') + '</td><td class="muted">已自動加入</td>';
    body.prepend(row);
  };
  const addCategoryChip = (category) => {
    const bar = document.getElementById('supplierCategoryChips');
    if (!bar || !category) return;
    const existing = bar.querySelector('[data-supplier-category="' + CSS.escape(category) + '"]');
    if (existing) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'stock-chip category';
    button.setAttribute('data-supplier-category', category);
    button.textContent = category;
    bar.appendChild(button);
  };
  const rememberSource = async (force) => {
    const value = String(input.value || '').trim();
    if (!value) {
      renderMenu();
      return;
    }
    if (!force && normalize(value) === normalize(lastSaved)) return;
    try {
      const form = new FormData();
      form.append('action', 'save_purchase_source');
      form.append('ajax', '1');
      form.append('purchase_source', value);
      const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: form, credentials: 'same-origin' });
      const payload = await response.json();
      if (!payload || !payload.ok) throw new Error(payload && payload.error ? payload.error : '儲存失敗');
      if (Array.isArray(payload.sources)) {
        purchaseSourceOptions.splice(0, purchaseSourceOptions.length, ...payload.sources);
      } else if (!currentSources().some((item) => normalize(item) === normalize(value))) {
        purchaseSourceOptions.push(value);
      }
      lastSaved = value;
      input.dataset.current = value;
      applyCnyIfNeeded(value);
      if (payload.supplier) {
        syncSupplierSelects(payload.supplier);
        addSupplierRow(payload.supplier, payload.supplier_category || '產品建檔供應來源');
        addCategoryChip(payload.supplier_category || '產品建檔供應來源');
      }
      setStatus(payload.notice || '已自動儲存。');
      renderMenu();
    } catch (error) {
      setStatus(error && error.message ? error.message : '供應來源儲存失敗，請再試一次。');
    }
  };
  const pickSource = (value) => {
    input.value = String(value || '').trim();
    hideMenu();
    applyCnyIfNeeded(input.value);
    input.dispatchEvent(new Event('change', { bubbles: true }));
    rememberSource();
  };
  input.addEventListener('focus', renderMenu);
  input.addEventListener('input', () => { activeIndex = -1; renderMenu(); });
  input.addEventListener('change', () => applyCnyIfNeeded(String(input.value || '').trim()));
  input.addEventListener('keydown', (event) => {
    const options = Array.from(menu.querySelectorAll('.spec-combo-option'));
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      if (menu.hidden) renderMenu();
      activeIndex = Math.min(options.length - 1, activeIndex + 1);
      renderMenu();
      return;
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault();
      activeIndex = Math.max(0, activeIndex - 1);
      renderMenu();
      return;
    }
    if (event.key === 'Enter') {
      event.preventDefault();
      if (activeIndex >= 0 && options[activeIndex]) {
        pickSource(decodeURIComponent(options[activeIndex].dataset.source || ''));
        return;
      }
      rememberSource(true);
      return;
    }
    if (event.key === 'Escape') hideMenu();
  });
  input.addEventListener('blur', () => { rememberSource(); });
  menu.addEventListener('mousedown', (event) => {
    const option = event.target.closest('.spec-combo-option');
    if (!option) return;
    event.preventDefault();
    pickSource(decodeURIComponent(option.dataset.source || ''));
  });
  document.addEventListener('click', (event) => {
    if (!combo.contains(event.target)) hideMenu();
  });
}
setupPurchaseSourceCombo();
function setupSupplierCategoryChips() {
  const bar = document.getElementById('supplierCategoryChips');
  const table = document.getElementById('supplierDirectoryTable');
  if (!bar || !table) return;
  bar.addEventListener('click', (event) => {
    const chip = event.target.closest('[data-supplier-category]');
    if (!chip) return;
    const category = String(chip.getAttribute('data-supplier-category') || '');
    bar.querySelectorAll('[data-supplier-category]').forEach((item) => item.classList.toggle('is-active', item === chip));
    table.querySelectorAll('tbody tr').forEach((row) => {
      if (row.getAttribute('data-supplier-empty')) return;
      const rowCat = String(row.getAttribute('data-supplier-category') || '');
      row.style.display = !category || rowCat === category ? '' : 'none';
    });
    const section = document.getElementById('suppliers');
    const searchInput = section && section.querySelector('[data-ops-search-panel] [data-search-q]');
    if (searchInput && category) searchInput.value = category;
  });
}
setupSupplierCategoryChips();
function setupProductCategoryCascade() {
  const groupInput = document.getElementById('productCategoryGroupInput');
  const typeHidden = document.getElementById('productCategoryTypeInput');
  const typeCombo = document.getElementById('productCategoryTypeCombo');
  const typeMenu = document.getElementById('productCategoryTypeMenu');
  const brandInput = document.getElementById('productCategoryBrandSelect');
  const brandMenu = document.getElementById('productCategoryBrandMenu');
  const specInput = document.getElementById('productCategorySpecSelect');
  const specMenu = document.getElementById('productCategorySpecMenu');
  const departmentSelect = document.getElementById('productDepartmentSelect');
  const serialInput = document.getElementById('productSerialInput');
  const barcodeInput = document.getElementById('productBarcodeInput');
  const barcodeHint = document.getElementById('productBarcodeHint');
  const editingInput = document.querySelector('#productMasterForm input[name="editing_product_id"]');
  const submit = document.getElementById('productMasterSubmit');
  if (!groupInput || !typeHidden || !typeCombo || !brandInput || !specInput || !barcodeInput || !barcodeHint || !submit) return;
  const isEditing = Boolean(String(editingInput?.value || '').trim());
  const existingSerial = String(serialInput?.dataset.existing || serialInput?.value || '').trim();
  const uniqueList = (values) => Array.from(new Set((values || []).map((value) => String(value || '').trim()).filter(Boolean))).sort((a, b) => a.localeCompare(b, 'zh-Hant'));
  const currentGroup = () => String(groupInput.value || '組裝硬體').trim() || '組裝硬體';
  const currentType = () => String(typeCombo.value || '').trim();
  const currentBrand = () => String(brandInput.value || '').trim();
  const currentSpec = () => String(specInput.value || '').trim();
  const rulesFor = (group, type, brand) => (productCategoryRules || []).filter((rule) => {
    if (group && String(rule.group || '').trim() !== group) return false;
    if (type && String(rule.type || '').trim() !== type) return false;
    if (brand != null && brand !== '' && String(rule.brand || '').trim() !== brand) return false;
    return true;
  });
  const typeOptions = () => uniqueList(((productCategoryTypesByGroup && productCategoryTypesByGroup[currentGroup()]) || []).concat((sharedCategoryTypes || [])).concat((productCategoryRules || []).filter((rule) => String(rule.group || '').trim() === currentGroup() || !currentGroup()).map((rule) => rule.type)));
  const brandOptions = () => uniqueList(rulesFor(currentGroup(), currentType()).map((rule) => rule.brand).concat((productCategoryRules || []).filter((rule) => String(rule.type || '').trim() === currentType()).map((rule) => rule.brand)));
  const specOptions = () => uniqueList(rulesFor(currentGroup(), currentType(), currentBrand()).map((rule) => rule.spec));
  const renderMenu = (menu, options, query) => {
    if (!menu) return;
    const q = String(query || '').trim().toLowerCase();
    const list = q ? options.filter((item) => item.toLowerCase().includes(q)) : options;
    if (!list.length) {
      menu.innerHTML = '<div class="spec-combo-empty">沒有符合項目，可直接打字後按儲存</div>';
      menu.hidden = false;
      return;
    }
    menu.innerHTML = list.slice(0, 40).map((item) => '<button type="button" class="spec-combo-option" data-value="' + encodeURIComponent(item) + '">' + escapeHtml(item) + '</button>').join('');
    menu.hidden = false;
  };
  const bindCombo = (input, menu, getOptions, onPick) => {
    if (!input || !menu) return;
    const show = () => renderMenu(menu, getOptions(), input.value);
    input.addEventListener('focus', show);
    input.addEventListener('input', () => { show(); onPick(); });
    input.addEventListener('change', onPick);
    menu.addEventListener('mousedown', (event) => {
      const option = event.target.closest('.spec-combo-option');
      if (!option) return;
      event.preventDefault();
      input.value = decodeURIComponent(option.dataset.value || '');
      menu.hidden = true;
      onPick();
    });
    document.addEventListener('click', (event) => {
      if (!input.contains(event.target) && !menu.contains(event.target)) menu.hidden = true;
    });
  };
  const firstPickedCode = (value) => String(value || '').split(/[、,，\s]+/).map((item) => item.trim()).filter(Boolean)[0] || '';
  const printedBarcode = (base, cost, color) => {
    const serial = String(base || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (!serial) return '';
    return serial + 'P' + String(Math.max(0, Math.round(Number(cost) || 0))) + String(color || '');
  };
  const updateBarcodePreview = () => {
    const group = currentGroup();
    const type = currentType();
    groupInput.value = group;
    typeHidden.value = type;
    const matchedRule = rulesFor(group, type, currentBrand()).find((item) => String(item.spec || '').trim() === currentSpec()) || rulesFor(group, type)[0] || null;
    const typeCode = String(matchedRule?.type_code || categoryTypeCodeMap[type] || (type ? 'ITEM' : '')).trim();
    const serial = isEditing ? existingSerial : String(matchedRule?.next_serial || (typeCode ? typeCode + '001' : ''));
    const cost = document.getElementById('productCostInput')?.value || 0;
    const color = firstPickedCode(document.getElementById('productColorCodeInput')?.value || '');
    const preview = printedBarcode(serial, cost, color);
    if (serialInput) {
      serialInput.value = serial;
      serialInput.dataset.systemValue = serial;
    }
    if (!type) {
      barcodeInput.value = '';
      barcodeHint.textContent = '先選或手打分類大綱。主機 COMPUTER 會編成 COM001P15096。';
      submit.disabled = !isEditing;
      return;
    }
    if (!typeCode) {
      barcodeInput.value = '';
      barcodeHint.textContent = '「' + type + '」還沒有分類英文碼。可先按「儲存此分類」，或到產品分類填例如 COM。';
      submit.disabled = !isEditing;
      return;
    }
    barcodeInput.value = preview;
    barcodeHint.textContent = isEditing
      ? ('流水 ' + serial + ' 保留；列印條碼依成本與顏色更新為 ' + preview + '。')
      : ('目前編號 ' + (preview || (serial + 'P成本＋顏色碼')) + '。手打的分類／品牌／細分類可按旁邊儲存，下次就能選。');
    submit.disabled = false;
  };
  window.refreshProductIdentityPreview = updateBarcodePreview;
  const saveOption = (buttonId, extra) => {
    const button = document.getElementById(buttonId);
    if (!button) return;
    button.addEventListener('click', async () => {
      const type = currentType();
      if (!type) { alert('請先輸入分類大綱。'); return; }
      button.disabled = true;
      const original = button.textContent;
      button.textContent = '儲存中...';
      try {
        const form = new FormData();
        form.append('action', 'save_product_category_option');
        form.append('ajax', '1');
        form.append('category_group', currentGroup());
        form.append('department', String(departmentSelect?.value || '電腦部門'));
        form.append('category_type', type);
        form.append('category_brand', extra.includeBrand ? currentBrand() : '');
        form.append('category_spec', extra.includeSpec ? currentSpec() : '');
        const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: form, credentials: 'same-origin' });
        const payload = await response.json();
        if (!payload || !payload.ok) throw new Error(payload && payload.error ? payload.error : '儲存失敗');
        if (payload.rule) {
          const existing = (productCategoryRules || []).findIndex((item) => String(item.id || '') === String(payload.rule.id || ''));
          if (existing >= 0) productCategoryRules[existing] = Object.assign({}, productCategoryRules[existing], payload.rule);
          else productCategoryRules.push(payload.rule);
          if (payload.rule.type && !(sharedCategoryTypes || []).includes(payload.rule.type)) sharedCategoryTypes.push(payload.rule.type);
        }
        alert(payload.notice || '已記住，下次可直接選。');
        updateBarcodePreview();
      } catch (error) {
        alert(error && error.message ? error.message : '選單紀錄儲存失敗');
      } finally {
        button.disabled = false;
        button.textContent = original;
      }
    });
  };
  bindCombo(typeCombo, typeMenu, typeOptions, updateBarcodePreview);
  bindCombo(brandInput, brandMenu, brandOptions, updateBarcodePreview);
  bindCombo(specInput, specMenu, specOptions, updateBarcodePreview);
  saveOption('saveCategoryTypeOption', { includeBrand: false, includeSpec: false });
  saveOption('saveCategoryBrandOption', { includeBrand: true, includeSpec: false });
  saveOption('saveCategorySpecOption', { includeBrand: true, includeSpec: true });
  if (groupInput && groupInput.tagName === 'SELECT') groupInput.addEventListener('change', updateBarcodePreview);
  if (departmentSelect) departmentSelect.addEventListener('change', () => {
    updateBarcodePreview();
  });
  groupInput.value = currentGroup();
  typeHidden.value = currentType();
  updateBarcodePreview();
}

setupProductCategoryCascade();
function setupSalesCustomerAutofill() {
  const nameInput = document.getElementById('salesCustomerName');
  const phoneInput = document.getElementById('salesCustomerPhone');
  const addressInput = document.getElementById('salesCustomerAddress');
  if (!nameInput || !phoneInput || !addressInput) return;
  const normalize = (value) => String(value || '').trim().toLowerCase();
  const findCustomer = (value) => {
    const query = normalize(value);
    if (!query) return null;
    const rows = Array.isArray(salesCustomerDirectory) ? salesCustomerDirectory : [];
    const exact = rows.filter((row) => normalize(row.name) === query || (row.aliases || []).some((alias) => normalize(alias) === query));
    if (exact.length === 1) return exact[0];
    if (exact.length > 1) {
      return exact.slice().sort((a, b) => {
        const score = (row) => Number(Boolean(row.phone)) + Number(Boolean(row.address));
        return score(b) - score(a);
      })[0];
    }
    const partial = rows.filter((row) => normalize(row.name).includes(query) || (row.aliases || []).some((alias) => normalize(alias).includes(query)));
    return partial.length === 1 ? partial[0] : null;
  };
  const fill = () => {
    const row = findCustomer(nameInput.value);
    if (!row) return;
    phoneInput.value = row.phone || '';
    addressInput.value = row.address || '';
  };
  nameInput.addEventListener('change', fill);
  nameInput.addEventListener('blur', fill);
  nameInput.addEventListener('input', () => {
    if (findCustomer(nameInput.value)) fill();
  });
}
setupSalesCustomerAutofill();
function sanitizeProductSerialInput() {
  document.querySelectorAll('input[name="id"][data-system-value]').forEach((input) => {
    const value = String(input.value || '').trim();
    const systemValue = input.dataset.systemValue || '';
    if (!value || /<\/?script|defer\s+src|<|>/i.test(value)) input.value = systemValue;
    input.setAttribute('autocomplete', 'off');
  });
}

sanitizeProductSerialInput();

function showOpsTab(id) {
  if (window.openOpsTab) return window.openOpsTab(id);
}
document.querySelectorAll('[data-order-check-all]').forEach((box) => {
  box.addEventListener('change', () => {
    box.closest('table')?.querySelectorAll('tbody input[type="checkbox"]').forEach((item) => item.checked = box.checked);
  });
});

(function(){
  const status = document.getElementById('opsSyncStatus');
  async function syncOpsStatus(){
    if (!status) return;
    try {
      const response = await fetch('operations.php?partial=ops_status', {cache:'no-store', credentials:'same-origin'});
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const data = await response.json();
      status.classList.remove('is-error');
      status.textContent = `最後同步：${data.time}｜待上架 ${data.upcoming}｜24小時截標 ${data.closingSoon}｜未出貨 ${data.unshippedBuyers}`;
    } catch (error) {
      status.classList.add('is-error');
      status.textContent = '同步失敗，保留目前資料';
    }
  }
  syncOpsStatus();
  setInterval(syncOpsStatus, 60000);
})();
(function(){
  const defaultDepartment = '電腦部門';
  function filterShelfPairByDepartment(deptSelect, pairSelect) {
    if (!deptSelect || !pairSelect) return;
    const dept = (deptSelect.value || defaultDepartment).trim();
    const options = pairSelect.querySelectorAll('option[data-department]');
    let keepValue = '';
    options.forEach((opt) => {
      const match = (opt.dataset.department || '') === dept;
      opt.hidden = !match;
      opt.disabled = !match;
      if (match && opt.value === pairSelect.value) keepValue = opt.value;
    });
    if (!keepValue) pairSelect.value = '';
  }
  const addDept = document.getElementById('warehouseAddDepartmentSelect');
  const pairSelect = document.getElementById('warehouseShelfPairSelect');
  if (addDept && pairSelect) {
    addDept.addEventListener('change', () => filterShelfPairByDepartment(addDept, pairSelect));
    filterShelfPairByDepartment(addDept, pairSelect);
  }

  const input = document.getElementById('warehouseTreeSearch');
  const select = document.getElementById('warehouseTreeSelect');
  const deptSelect = document.getElementById('warehouseTreeDepartmentSelect');
  const tree = document.getElementById('warehouseTree');
  if (!tree) return;
  function escapeOption(value) {
    return String(value || '').replace(/[&<>"']/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  }
  function refreshWarehouseSelect() {
    if (!select) return;
    const selectedDept = (deptSelect?.value || '').trim();
    const current = select.value;
    const names = [];
    tree.querySelectorAll('.warehouse-tree-card').forEach((card) => {
      const cardDept = (card.dataset.department || '').trim();
      if (selectedDept && cardDept !== selectedDept) return;
      const name = (card.dataset.warehouse || '').trim();
      if (name && !names.includes(name)) names.push(name);
    });
    select.innerHTML = '<option value="">全部主倉位</option>' + names.map((name) => `<option value="${escapeOption(name)}">${escapeOption(name)}</option>`).join('');
    select.value = names.includes(current) ? current : '';
  }
  function filterWarehouseTree(){
    const keyword = (input?.value || '').trim().toLowerCase();
    const selectedWarehouse = (select?.value || '').trim().toLowerCase();
    const selectedDept = (deptSelect?.value || '').trim();
    tree.querySelectorAll('.warehouse-tree-card').forEach((card) => {
      const cardText = (card.dataset.warehouseSearch || '').toLowerCase();
      const warehouseName = (card.dataset.warehouse || '').trim().toLowerCase();
      const cardDept = (card.dataset.department || '').trim();
      const deptOk = !selectedDept || cardDept === selectedDept;
      const warehouseOk = !selectedWarehouse || warehouseName === selectedWarehouse;
      const cardHit = !keyword || cardText.includes(keyword);
      let rowHitCount = 0;
      card.querySelectorAll('.warehouse-tree-shelf').forEach((row) => {
        const hit = deptOk && warehouseOk && (!keyword || cardHit || (row.dataset.warehouseSearch || '').toLowerCase().includes(keyword));
        row.style.display = hit ? '' : 'none';
        if (hit) rowHitCount++;
      });
      card.classList.toggle('is-hidden', !deptOk || !warehouseOk || (!!keyword && !cardHit && rowHitCount === 0));
    });
  }
  input?.addEventListener('input', filterWarehouseTree);
  select?.addEventListener('change', filterWarehouseTree);
  deptSelect?.addEventListener('change', () => {
    refreshWarehouseSelect();
    filterWarehouseTree();
  });
  refreshWarehouseSelect();
  filterWarehouseTree();
})();
document.querySelectorAll('.copy-order-message').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const text = btn.previousElementSibling?.querySelector('textarea')?.value || '';
    try { await navigator.clipboard.writeText(text); btn.textContent = '已複製'; setTimeout(() => btn.textContent = '複製核對文字', 1200); }
    catch (e) { alert('複製失敗，請手動選取文字'); }
  });
});
function productDescriptionConvertPrompt() {
  const source = (document.getElementById('productDescriptionSource')?.value || '').trim();
  const id = document.getElementById('productBarcodeInput')?.value
    || document.getElementById('productSerialInput')?.value
    || '';
  const title = document.querySelector('#products [name="title"]')?.value
    || document.querySelector('[name="title"]')?.value
    || '';
  const spec = [
    document.getElementById('productColorInput')?.value || '',
    document.getElementById('productSizeInput')?.value || '',
    document.getElementById('productSpecInput')?.value || ''
  ].filter(Boolean).join(' / ');
  return [
    '請協助整理成繁體中文商品描述，保留產品編號、產品名稱、規格，語氣適合 Facebook 電商營運上架。不要編造未提供的資訊。',
    `產品編號：${id}`,
    `產品名稱：${title}`,
    `規格：${spec || '-'}`,
    '原始產品描述：',
    source || '（尚未填產品描述）'
  ].join('\n');
}
async function copyProductDescriptionPrompt() {
  const source = (document.getElementById('productDescriptionSource')?.value || '').trim();
  if (!source) {
    alert('請先在上面填產品描述，才能轉換。');
    return false;
  }
  const prompt = productDescriptionConvertPrompt();
  try { await navigator.clipboard.writeText(prompt); }
  catch (e) {
    const box = document.getElementById('productDescriptionAi');
    if (box) { box.focus(); box.select(); }
  }
  return true;
}
document.getElementById('copyProductDescriptionPrompt')?.addEventListener('click', async () => {
  if (await copyProductDescriptionPrompt()) alert('已複製轉換提示，可貼到 ChatGPT。');
});
document.getElementById('convertProductDescription')?.addEventListener('click', async () => {
  const source = (document.getElementById('productDescriptionSource')?.value || '').trim();
  const target = document.getElementById('productDescriptionAi');
  if (!source) {
    alert('請先在上面填產品描述，才能轉換。');
    document.getElementById('productDescriptionSource')?.focus();
    return;
  }
  const copied = await copyProductDescriptionPrompt();
  if (target && !String(target.value || '').trim()) target.value = source;
  target?.focus();
  if (copied) alert('已複製轉換提示。請貼到 ChatGPT，再把整理後的文案貼到下面「AI 整理後」。');
});
function buildAiMaterialText() {
  const rows = Array.from(document.querySelectorAll('.product-check:checked')).map((cb) => cb.closest('tr')).filter(Boolean);
  const output = document.getElementById('aiMaterialOutput');
  if (!output) return;
  if (!rows.length) {
    output.value = '請先勾選要整理的產品。';
    return;
  }
  output.value = rows.map((row, index) => {
    const images = (row.dataset.productImages || '').split('\n').filter(Boolean);
    return [
      `【產品 ${index + 1}】`,
      `產品編號：${row.dataset.productId || ''}`,
      `產品名稱：${row.dataset.productTitle || ''}`,
      `規格：${row.dataset.productSpec || ''}`,
      `原始產品描述：${row.dataset.productDesc || ''}`,
      `產品照片：`,
      images.length ? images.map((img, i) => `${i + 1}. ${img}`).join('\n') : '無',
      '',
      '請協助整理成繁體中文商品描述，保留產品編號、產品名稱、規格，語氣適合 Facebook 電商營運上架。'
    ].join('\n');
  }).join('\n\n---\n\n');
}
document.getElementById('buildAiMaterial')?.addEventListener('click', buildAiMaterialText);
document.getElementById('copyAiMaterial')?.addEventListener('click', async () => {
  const output = document.getElementById('aiMaterialOutput');
  if (!output) return;
  output.select();
  try { await navigator.clipboard.writeText(output.value); } catch (e) { document.execCommand('copy'); }
});
document.querySelectorAll('.copy-order-link').forEach((btn) => btn.addEventListener('click', async () => {
  const input = btn.closest('.settlement-card')?.querySelector('.buyer-order-link');
  const text = input?.value || '';
  if (!text || text.includes('請先儲存')) return;
  try { await navigator.clipboard.writeText(text); } catch (e) { input?.select(); document.execCommand('copy'); }
}));
document.querySelectorAll('.copy-notice').forEach((btn) => btn.addEventListener('click', async () => {
  const text = btn.closest('.settlement-card')?.querySelector('.winner-notice')?.value || '';
  try { await navigator.clipboard.writeText(text); } catch (e) { const el = btn.closest('.settlement-card')?.querySelector('.winner-notice'); el?.select(); document.execCommand('copy'); }
}));
document.querySelectorAll('.copy-auction-notice').forEach((btn) => btn.addEventListener('click', async () => {
  const text = btn.closest('.settlement-card')?.querySelector('.auction-notice')?.value || '';
  try { await navigator.clipboard.writeText(text); } catch (e) { const el = btn.closest('.settlement-card')?.querySelector('.auction-notice'); el?.select(); document.execCommand('copy'); }
}));
const legacyBarcodeColorCodes = <?php echo json_encode($barcodeColorCodes, JSON_UNESCAPED_UNICODE); ?>;
const legacyBarcodeSizeCodes = <?php echo json_encode($barcodeSizeCodes, JSON_UNESCAPED_UNICODE); ?>;
function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch]));
}
function splitPickedValues(value) {
  return String(value || '').split(/[、,，\n]/).map((item) => item.trim()).filter(Boolean);
}
function setupProductCodePicker(config) {
  const select = document.getElementById(config.selectId);
  const add = document.getElementById(config.addId);
  const nameInput = document.getElementById(config.nameInputId);
  const codeInput = document.getElementById(config.codeInputId);
  const list = document.getElementById(config.listId);
  if (!select || !add || !nameInput || !codeInput || !list) return null;
  let items = [];
  const syncInputs = () => {
    nameInput.value = items.map((item) => item.name).join('、');
    codeInput.value = items.map((item) => item.code).join('、');
  };
  const render = () => {
    list.innerHTML = items.map((item, index) => '<span class="product-picked-chip"><b>' + escapeHtml(item.code) + '</b>' + escapeHtml(item.name) + '<button type="button" data-remove="' + index + '">×</button></span>').join('');
    syncInputs();
    if (typeof window.refreshProductIdentityPreview === 'function') window.refreshProductIdentityPreview();
  };
  const normalizeInitial = () => {
    const names = splitPickedValues(nameInput.value);
    const codes = splitPickedValues(codeInput.value);
    items = [];
    const count = Math.max(names.length, codes.length);
    for (let i = 0; i < count; i += 1) {
      const code = codes[i] || '';
      const name = names[i] || config.map[code] || '';
      if (code || name) items.push({ code, name });
    }
    render();
  };
  const addPair = (code, name) => {
    code = String(code || '').trim();
    name = String(name || '').trim();
    if (!code && !name) return;
    if (items.some((item) => item.code === code && item.name === name)) return;
    items.push({ code, name });
    render();
  };
  add.addEventListener('click', () => {
    const raw = select.value || '';
    if (!raw) return;
    const parts = raw.split('|');
    addPair(parts[0] || '', parts.slice(1).join('|') || config.map[parts[0]] || '');
    select.value = '';
  });
  list.addEventListener('click', (event) => {
    const btn = event.target.closest('[data-remove]');
    if (!btn) return;
    items.splice(Number(btn.dataset.remove), 1);
    render();
  });
  normalizeInitial();
  return { addPair, render, getItems: () => items.slice(), clear: () => { items = []; render(); } };
}
const productColorPicker = setupProductCodePicker({ selectId: 'productColorPairSelect', addId: 'addProductColorPair', nameInputId: 'productColorInput', codeInputId: 'productColorCodeInput', listId: 'productColorPicked', map: legacyBarcodeColorCodes });
const productSizePicker = setupProductCodePicker({ selectId: 'productSizePairSelect', addId: 'addProductSizePair', nameInputId: 'productSizeInput', codeInputId: 'productSizeCodeInput', listId: 'productSizePicked', map: legacyBarcodeSizeCodes });
function parseLegacyAuctionBarcode(raw) {
  const barcode = String(raw || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
  const pIndex = barcode.indexOf('P');
  if (pIndex <= 0) return null;
  const productPrefix = barcode.slice(0, pIndex);
  const tail = barcode.slice(pIndex + 1).replace(/[^0-9]/g, '');
  if (!tail) return null;
  const codeKeys = (map) => Object.keys(map).sort((a, b) => b.length - a.length || b.localeCompare(a));
  const colorKeysRaw = codeKeys(legacyBarcodeColorCodes);
  const latestColors = colorKeysRaw.filter((code) => /^9\d{2}$/.test(code));
  const oldColors = colorKeysRaw.filter((code) => !/^9\d{2}$/.test(code));
  const colorKeys = latestColors.concat(oldColors);
  const sizeKeys = codeKeys(legacyBarcodeSizeCodes);
  const commonSizes = ['00', '1', '2', '3', '4', '5', '6', '7', '8'];
  const preferredSizeKeys = commonSizes.concat(sizeKeys.filter((key) => !commonSizes.includes(key)));
  let parsed = null;

  const fullTailColor = latestColors.find((code) => tail.endsWith(code) && tail.length > code.length);
  if (fullTailColor) {
    parsed = { cost: tail.slice(0, -fullTailColor.length), colorCode: fullTailColor, sizeCode: '' };
  }

  if (!parsed) for (const sizeCode of preferredSizeKeys) {
    if (!tail.endsWith(sizeCode)) continue;
    const beforeSize = tail.slice(0, -sizeCode.length);
    for (const colorCode of colorKeys) {
      if (!beforeSize.endsWith(colorCode)) continue;
      const cost = beforeSize.slice(0, -colorCode.length);
      if (!cost) continue;
      parsed = { cost, colorCode, sizeCode };
      break;
    }
    if (parsed) break;
  }

  if (!parsed) {
    let cost = tail;
    let sizeCode = '';
    let colorCode = '';
    for (const key of sizeKeys) {
      if (tail.endsWith(key)) {
        sizeCode = key;
        cost = tail.slice(0, -key.length);
        break;
      }
    }
    for (const key of colorKeys) {
      if (cost.endsWith(key)) {
        colorCode = key;
        cost = cost.slice(0, -key.length);
        break;
      }
    }
    if (!colorCode && tail.length > 2) {
      colorCode = tail.slice(-2);
      cost = tail.slice(0, -2);
    }
    parsed = { cost, colorCode, sizeCode };
  }

  return {
    productPrefix,
    cost: parsed.cost || '',
    colorCode: parsed.colorCode || '',
    colorName: legacyBarcodeColorCodes[parsed.colorCode] || '',
    sizeCode: parsed.sizeCode || '',
    sizeName: legacyBarcodeSizeCodes[parsed.sizeCode] || ''
  };
}
function bindProductBarcodeParser() {
  const input = document.getElementById('productBarcodeInput');
  if (!input || input.readOnly) return;
  const color = document.getElementById('productColorInput');
  const colorCode = document.getElementById('productColorCodeInput');
  const size = document.getElementById('productSizeInput');
  const sizeCode = document.getElementById('productSizeCodeInput');
  const barcodeCost = document.getElementById('productBarcodeCostInput');
  const cost = document.getElementById('productCostInput');
  const apply = () => {
    const parsed = parseLegacyAuctionBarcode(input.value);
    if (!parsed) return;
    if (productColorPicker && parsed.colorCode) productColorPicker.addPair(parsed.colorCode, parsed.colorName || legacyBarcodeColorCodes[parsed.colorCode] || '');
    else {
      if (color && !color.value) color.value = parsed.colorName;
      if (colorCode) colorCode.value = parsed.colorCode;
    }
    if (productSizePicker && parsed.sizeCode) productSizePicker.addPair(parsed.sizeCode, parsed.sizeName || legacyBarcodeSizeCodes[parsed.sizeCode] || '');
    else {
      if (size && !size.value) size.value = parsed.sizeName;
      if (sizeCode) sizeCode.value = parsed.sizeCode;
    }
    if (barcodeCost) barcodeCost.value = parsed.cost;
    if (cost && (!Number(cost.value) || Number(cost.value) === 0)) cost.value = parsed.cost;
  };
  input.addEventListener('change', apply);
  input.addEventListener('blur', apply);
}
bindProductBarcodeParser();
function refreshProductPurchaseConversion() {
  const currency = document.getElementById('productPurchaseCurrency');
  const unitCost = document.getElementById('productPurchaseUnitCost');
  const rate = document.getElementById('productPurchaseRate');
  const twdCost = document.getElementById('productCostInput');
  const hint = document.getElementById('productPurchaseConversionHint');
  if (!currency || !unitCost || !rate || !twdCost) return;

  const sourceAmount = Math.max(0, Number(unitCost.value || 0));
  const exchangeRate = Math.max(0.0001, Number(rate.value || 1));
  if (sourceAmount <= 0) {
    if (hint) hint.textContent = '填入來源單價後會自動換算台幣成本。';
    if (typeof window.refreshProductIdentityPreview === 'function') window.refreshProductIdentityPreview();
    return;
  }

  const converted = Math.round((sourceAmount * exchangeRate + Number.EPSILON) * 100) / 100;
  twdCost.value = converted.toFixed(2);
  if (hint) {
    const currencyLabel = currency.value === 'CNY' ? '人民幣' : '台幣';
    hint.textContent = `換算：${currencyLabel} ${sourceAmount} × ${exchangeRate} = 台幣 ${converted.toFixed(2)} 元`;
  }
  if (typeof window.refreshProductIdentityPreview === 'function') window.refreshProductIdentityPreview();
}
document.getElementById('productPurchaseCurrency')?.addEventListener('change', event => {
  const rate = document.getElementById('productPurchaseRate');
  if (rate) rate.value = event.currentTarget.value === 'CNY' ? String(<?=json_encode((float)($purchaseCostSettings['rmb_fixed_rate'] ?? 5))?>) : '1';
  refreshProductPurchaseConversion();
});
document.getElementById('productPurchaseSource')?.addEventListener('change', event => {
  if (!['拼多多','豪鴻'].includes(event.currentTarget.value)) return;
  const currency = document.getElementById('productPurchaseCurrency');
  const rate = document.getElementById('productPurchaseRate');
  if (currency) currency.value = 'CNY';
  if (rate) rate.value = String(<?=json_encode((float)($purchaseCostSettings['rmb_fixed_rate'] ?? 5))?>);
  refreshProductPurchaseConversion();
});
document.getElementById('productPurchaseUnitCost')?.addEventListener('input', refreshProductPurchaseConversion);
document.getElementById('productPurchaseRate')?.addEventListener('input', refreshProductPurchaseConversion);
refreshProductPurchaseConversion();
const scheduleProducts = <?php echo json_encode(array_map(function($p){ return ['id'=>$p['id']??'', 'barcode'=>$p['barcode']??'', 'title'=>$p['title']??'', 'product_condition'=>$p['product_condition']??'', 'category_group'=>$p['category_group']??'', 'category_type'=>$p['category_type']??'', 'category_brand'=>$p['category_brand']??'', 'category_spec'=>$p['category_spec']??'', 'color'=>$p['color']??'', 'color_code'=>$p['color_code']??'', 'size'=>$p['size']??'', 'size_code'=>$p['size_code']??'', 'spec'=>$p['spec']??'', 'warehouse_name'=>$p['warehouse_name']??'', 'shelf_code'=>$p['shelf_code']??'', 'warehouse_location'=>$p['warehouse_location']??'', 'stock_total'=>(int)($p['stock_total']??0), 'stock_reserved'=>(int)($p['stock_reserved']??0), 'cloud_auction_reserved'=>(int)($p['cloud_auction_reserved']??0), 'cloud_auction_locked'=>!empty($p['cloud_auction_locked']), 'stock_sold'=>(int)($p['stock_sold']??0), 'cost'=>(float)($p['cost']??0), 'purchase_source'=>$p['purchase_source']??'其他', 'purchase_source_currency'=>$p['purchase_source_currency']??'TWD', 'purchase_source_unit_cost'=>(float)($p['purchase_source_unit_cost']??0), 'purchase_exchange_rate'=>(float)($p['purchase_exchange_rate']??1), 'sale_price'=>(float)($p['sale_price']??0), 'reference_price'=>(float)($p['reference_price']??0), 'images'=>product_images($p), 'description'=>$p['description']??'']; }, $products), json_flags(JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>;
function scheduleProductReservedTotal(p){ return (Number(p.stock_reserved)||0) + (Number(p.cloud_auction_reserved)||0); }
function scheduleProductAvailableRaw(p){ return (Number(p.stock_total)||0) - scheduleProductReservedTotal(p) - (Number(p.stock_sold)||0); }
function scheduleProductAvailable(p){ return Math.max(0, scheduleProductAvailableRaw(p)); }
let inventoryCountAudioContext;
function playInventoryCountBeep(times, frequency) {
  const AudioContextClass = window.AudioContext || window.webkitAudioContext;
  if (!AudioContextClass) return;
  inventoryCountAudioContext = inventoryCountAudioContext || new AudioContextClass();
  if (inventoryCountAudioContext.state === 'suspended') inventoryCountAudioContext.resume();
  for (let index = 0; index < times; index += 1) {
    const start = inventoryCountAudioContext.currentTime + (index * 0.18);
    const oscillator = inventoryCountAudioContext.createOscillator();
    const gain = inventoryCountAudioContext.createGain();
    oscillator.type = 'sine';
    oscillator.frequency.setValueAtTime(frequency, start);
    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(0.2, start + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.1);
    oscillator.connect(gain);
    gain.connect(inventoryCountAudioContext.destination);
    oscillator.start(start);
    oscillator.stop(start + 0.11);
  }
}
function findInventoryCountProduct(scanCode) {
  const code = normalizeProductSearch(scanCode);
  if (!code) return null;
  const warehouse = String(document.getElementById('inventoryCountWarehouse')?.value || '').trim();
  return scheduleProducts.find(product => String(product.warehouse_name || '').trim() === warehouse && [product.id, product.barcode].some(value => normalizeProductSearch(value) === code)) || null;
}
let inventoryCountRows = [];
function inventoryCountNowText() {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}
function inventoryCountLineCode(row) {
  return String(row.barcode || row.id || row.scan_code || '').trim();
}
function syncInventoryCountLines() {
  const input = document.getElementById('inventoryCountLines');
  if (!input) return;
  input.value = inventoryCountRows.map(row => {
    const code = inventoryCountLineCode(row);
    const qty = Math.max(0, Number(row.qty) || 0);
    const time = String(row.checked_at || '').replace(' ', 'T');
    const note = String(row.note || '').trim();
    return `${code} ${qty}${time ? ` @${time}` : ''}${note ? ` ${note}` : ''}`;
  }).join('\n');
}
function renderInventoryCountRows() {
  const tbody = document.getElementById('inventoryCountLineRows');
  if (!tbody) return;
  if (!inventoryCountRows.length) {
    tbody.innerHTML = '<tr class="inventory-count-empty"><td colspan="9" class="muted">尚未掃描盤點明細。</td></tr>';
    syncInventoryCountLines();
    return;
  }
  tbody.innerHTML = inventoryCountRows.map(row => {
    const images = Array.isArray(row.images) ? row.images : [];
    const code = inventoryCountLineCode(row);
    const spec = [row.color,row.size,row.spec].filter(Boolean).join(' / ') || '-';
    const systemQty = Math.max(0, Number(row.stock_total ?? row.stock ?? 0) || 0);
    const actualQty = Math.max(0, Number(row.qty) || 0);
    const diff = actualQty - systemQty;
    const diffText = `${diff > 0 ? '+' : ''}${diff}`;
    const diffClass = diff === 0 ? 'is-match' : (diff < 0 ? 'is-short' : 'is-over');
    const status = diff === 0 ? '庫存一致' : '待管理者確認';
    return `<tr data-inventory-count-code="${escapeHtml(code)}">
      <td>${images[0] ? `<img class="thumb zoomable" src="${escapeHtml(images[0])}">` : '<span class="muted">無圖</span>'}</td>
      <td><span class="inventory-count-product"><b>${escapeHtml(row.title || row.id || '-')}</b><small class="muted">${escapeHtml(code)}</small></span></td>
      <td>${escapeHtml(spec)}</td>
      <td><b>${systemQty}</b></td>
      <td><input class="inventory-count-qty" type="number" min="0" value="${actualQty}" data-inventory-count-qty="${escapeHtml(code)}"></td>
      <td class="inventory-diff ${diffClass}" data-inventory-count-diff>${diffText}</td>
      <td>${escapeHtml(row.checked_at || '')}</td>
      <td data-inventory-count-status>${escapeHtml(status)}</td>
      <td><button type="button" class="danger small" data-inventory-count-remove="${escapeHtml(code)}">刪除</button></td>
    </tr>`;
  }).join('');
  syncInventoryCountLines();
}
function appendInventoryCountLine(product, note) {
  const code = String(product.barcode || product.id || '').trim();
  const found = inventoryCountRows.find(row => normalizeProductSearch(inventoryCountLineCode(row)) === normalizeProductSearch(code));
  if (found) {
    found.qty = Math.max(1, Number(found.qty) || 1) + 1;
    found.checked_at = inventoryCountNowText();
    if (note && !found.note) found.note = note;
  } else {
    inventoryCountRows.push(Object.assign({}, product, { qty: 1, note: note || '', checked_at: inventoryCountNowText() }));
  }
  renderInventoryCountRows();
}
function setInventoryCountScanStatus(type, message) {
  const status = document.getElementById('inventoryCountScanStatus');
  if (!status) return;
  status.className = `inventory-scan-status${type ? ` is-${type}` : ''}`;
  status.textContent = message;
}
function processInventoryCountScan(rawCode) {
  const code = String(rawCode || '').trim();
  if (!code) return;
  const warehouse = String(document.getElementById('inventoryCountWarehouse')?.value || '').trim();
  if (!warehouse) {
    setInventoryCountScanStatus('missing', '請先選擇本次要盤點的倉庫，再開始掃描。');
    return;
  }
  const product = findInventoryCountProduct(code);
  if (!product) {
    playInventoryCountBeep(3, 330);
    setInventoryCountScanStatus('missing', `「${warehouse}」找不到條碼：${code}，未加入盤點明細。`);
    return;
  }
  const systemQty = Math.max(0, Number(product.stock_total ?? product.stock ?? 0) || 0);
  if (systemQty <= 0) {
    playInventoryCountBeep(2, 660);
    appendInventoryCountLine(product, '系統庫存0');
    setInventoryCountScanStatus('zero', `找到商品但系統庫存為 0：${product.title || product.id}，已加入盤點明細。`);
    return;
  }
  playInventoryCountBeep(1, 1040);
  appendInventoryCountLine(product, '');
  setInventoryCountScanStatus('ok', `掃描成功：${product.title || product.id}，目前系統庫存 ${systemQty}。`);
}
document.getElementById('inventoryCountScanner')?.addEventListener('keydown', event => {
  if (event.key !== 'Enter') return;
  event.preventDefault();
  const input = event.currentTarget;
  processInventoryCountScan(input.value);
  input.value = '';
  input.focus();
});
document.getElementById('inventoryCountLineRows')?.addEventListener('input', event => {
  const input = event.target.closest?.('[data-inventory-count-qty]');
  if (!input) return;
  const code = input.dataset.inventoryCountQty || '';
  const row = inventoryCountRows.find(item => inventoryCountLineCode(item) === code);
  if (row) {
    row.qty = Math.max(0, Number(input.value) || 0);
    const systemQty = Math.max(0, Number(row.stock_total ?? row.stock ?? 0) || 0);
    const diff = row.qty - systemQty;
    const tr = input.closest('tr');
    const diffCell = tr?.querySelector('[data-inventory-count-diff]');
    const statusCell = tr?.querySelector('[data-inventory-count-status]');
    if (diffCell) {
      diffCell.textContent = `${diff > 0 ? '+' : ''}${diff}`;
      diffCell.className = `inventory-diff ${diff === 0 ? 'is-match' : (diff < 0 ? 'is-short' : 'is-over')}`;
    }
    if (statusCell) statusCell.textContent = diff === 0 ? '庫存一致' : '待管理者確認';
  }
  syncInventoryCountLines();
});
document.getElementById('inventoryCountLineRows')?.addEventListener('click', event => {
  const btn = event.target.closest?.('[data-inventory-count-remove]');
  if (!btn) return;
  const code = btn.dataset.inventoryCountRemove || '';
  inventoryCountRows = inventoryCountRows.filter(row => inventoryCountLineCode(row) !== code);
  renderInventoryCountRows();
});
function scheduleProductStockText(p){ const raw = scheduleProductAvailableRaw(p); return raw < 0 ? `可用 0｜超排 ${Math.abs(raw)}` : `可用 ${raw}`; }
function normalizeProductSearch(value){ return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim(); }
function scheduleProductHaystack(p){
  return normalizeProductSearch([p.id,p.barcode,p.title,p.product_condition,p.category_group,p.category_type,p.category_brand,p.category_spec,p.color,p.color_code,p.size,p.size_code,p.spec,p.warehouse_name,p.shelf_code,p.warehouse_location].join(' '));
}
function findScheduleProduct(value){
  const q = normalizeProductSearch(value);
  const first = q.split('/')[0].trim();
  return scheduleProducts.find(p => [p.id,p.barcode,p.title].some(v => String(v||'').toLowerCase() === first))
    || scheduleProducts.find(p => scheduleProductHaystack(p).includes(q));
}
function refreshProductQuickStockCost() {
  const qty = Math.max(1, Number(document.getElementById('productQuickStockQty')?.value || 1));
  const unitCost = Math.max(0, Number(document.getElementById('productQuickStockUnitCost')?.value || 0));
  const base = qty * unitCost;
  const baseBox = document.getElementById('productQuickStockBase');
  const landedBox = document.getElementById('productQuickStockLanded');
  if (baseBox) baseBox.textContent = formatMoney(base);
  if (landedBox) landedBox.textContent = formatMoney(unitCost);
}
function setProductQuickStockProduct(product) {
  if (!product) return false;
  const barcode = document.getElementById('productQuickStockBarcode');
  const unitCost = document.getElementById('productQuickStockUnitCost');
  const info = document.getElementById('productQuickStockProductInfo');
  if (barcode) barcode.value = product.barcode || product.id || '';
  if (unitCost) unitCost.value = String(Number(product.cost || 0));
  if (productQuickStockLocationBinding) productQuickStockLocationBinding.setValues({warehouse:product.warehouse_name || defaultWarehouseForDepartment(product.department || '電腦部門'),shelf:product.shelf_code || '',location:product.warehouse_location || ''});
  if (info) info.innerHTML = `<b>${escapeHtml(product.id || '')} / ${escapeHtml(product.barcode || '')}</b>　${escapeHtml(product.title || '')}<br><span class="muted">${escapeHtml([product.color,product.size,product.spec].filter(Boolean).join(' / ') || '一般規格')}｜目前 ${escapeHtml(scheduleProductStockText(product))}</span>`;
  refreshProductQuickStockCost();
  return true;
}
document.getElementById('productQuickStockBarcode')?.addEventListener('keydown', event => {
  if (event.key !== 'Enter') return;
  event.preventDefault();
  const product = findScheduleProduct(event.currentTarget.value);
  const info = document.getElementById('productQuickStockProductInfo');
  if (!product) {
    if (info) info.textContent = `找不到條碼：${event.currentTarget.value}，請先建立產品主檔。`;
    return;
  }
  setProductQuickStockProduct(product);
  document.getElementById('productQuickStockQty')?.focus();
});
['productQuickStockQty','productQuickStockUnitCost'].forEach(id => {
  document.getElementById(id)?.addEventListener('input', refreshProductQuickStockCost);
});
document.getElementById('productQuickStockForm')?.addEventListener('submit', event => {
  const barcode = document.getElementById('productQuickStockBarcode');
  const product = findScheduleProduct(barcode?.value || '');
  if (!product) {
    event.preventDefault();
    const info = document.getElementById('productQuickStockProductInfo');
    if (info) info.textContent = '快速入庫失敗：找不到產品條碼，請先建立產品主檔。';
    return;
  }
  if (!window.confirm('確認依直接成本入庫？舊系統現貨不加運費、關稅或倉別加價。')) event.preventDefault();
});
function productSearchMatches(value, limit = 12) {
  const q = normalizeProductSearch(value);
  if (!q) return scheduleProducts.slice(0, limit);
  const terms = q.split(' ').filter(Boolean);
  return scheduleProducts.filter(p => {
    const haystack = scheduleProductHaystack(p);
    return terms.every(term => haystack.includes(term));
  }).slice(0, limit);
}
let pendingScheduleProductId = '';
function productDisplayValue(p) {
  return `${p.id}${p.barcode ? ' / ' + p.barcode : ''} / ${p.title || ''}`;
}
function stockPositionLabel(shelf, location) {
  const originalShelf = String(shelf || '').trim();
  const originalLocation = String(location || '').trim();
  const cleanShelf = originalShelf.replace(/[\(（]\s*(上層|下層)\s*[\)）]/gu, '').trim();
  if (!cleanShelf) return '還沒放上去';
  const cleanLocation = originalLocation.includes('上層') || originalShelf.includes('上層')
    ? '上層'
    : (originalLocation.includes('下層') || originalShelf.includes('下層') ? '下層' : '');
  return [cleanShelf, cleanLocation].filter(Boolean).join(' / ');
}
function productCardHtml(p, mode) {
  const images = Array.isArray(p.images) ? p.images : [];
  const available = scheduleProductAvailable(p);
  const selected = mode === 'schedule' && String(p.id || '') === String(pendingScheduleProductId || '') ? ' is-pending' : '';
  const zeroStock = available <= 0 ? ' is-zero-stock' : '';
  const actionText = mode === 'schedule' ? '選擇此產品' : '帶入庫存';
  const stockName = p.warehouse_name || '-';
  let stockPosition = stockPositionLabel(p.shelf_code, p.warehouse_location);
  if (stockPosition === stockName) stockPosition = '';
  return `<button type="button" class="product-result-card${selected}${zeroStock}" data-product-id="${escapeHtml(p.id)}" data-mode="${mode}">
    ${images[0] ? `<img src="${escapeHtml(images[0])}" alt="">` : `<span class="no-img">無圖</span>`}
    <span><b>${escapeHtml(p.id)}${p.barcode ? ' / ' + escapeHtml(p.barcode) : ''}</b><small>${escapeHtml(p.title || '')}</small><small>分類：${escapeHtml([p.category_group,p.category_type,p.category_brand,p.category_spec].filter(Boolean).join(' / ') || '-')}</small><small>狀態：${escapeHtml(p.product_condition || '未設定')}</small><small>規格：${escapeHtml([p.color,p.size,p.spec].filter(Boolean).join(' / ') || '-')}</small><small>庫存名稱：${escapeHtml(stockName)}</small>${stockPosition ? `<small>貨架 / 倉位：${escapeHtml(stockPosition)}</small>` : ''}<small class="${available <= 0 || p.cloud_auction_locked ? 'stock-warning' : ''}">總 ${p.stock_total || 0}｜預約 ${scheduleProductReservedTotal(p)}${Number(p.cloud_auction_reserved || 0) > 0 ? '（競標 ' + Number(p.cloud_auction_reserved || 0) + '）' : ''}｜已售 ${p.stock_sold || 0}｜${scheduleProductStockText(p)}${available <= 0 ? '｜需先入庫' : ''}${p.cloud_auction_locked ? '｜競標中禁止重複上架' : ''}</small></span>
    <span class="product-pick-action">${actionText}</span>
  </button>`;
}
function findProductById(id) {
  return scheduleProducts.find(item => String(item.id || '') === String(id || ''));
}
function setScheduleProduct(p) {
  pendingScheduleProductId = String(p.id || '');
  const confirm = document.getElementById('confirmScheduleProduct');
  const text = document.getElementById('schedulePendingProductText');
  if (confirm) confirm.disabled = false;
  if (text) text.textContent = `待確認：${productDisplayValue(p)}，可用庫存 ${scheduleProductAvailable(p)}`;
  renderScheduleProductResults();
  renderScheduleProductPreview(p, false);
}
function confirmScheduleProduct() {
  const p = findProductById(pendingScheduleProductId);
  if (!p) return;
  const input = document.getElementById('scheduleProductSearch');
  const hidden = document.getElementById('scheduleProductKey');
  const text = document.getElementById('schedulePendingProductText');
  if (input) input.value = productDisplayValue(p);
  if (hidden) hidden.value = p.id || '';
  if (text) text.textContent = `已確認帶入：${productDisplayValue(p)}，送出排程時會使用這個產品。`;
  renderScheduleProductPreview(p, true);
}
function renderScheduleProductResults(){
  const input = document.getElementById('scheduleProductSearch');
  const box = document.getElementById('scheduleProductResults');
  const hint = document.getElementById('scheduleProductSearchHint');
  if (!input || !box) return;
  const value = input.value || '';
  const matches = productSearchMatches(value, 8);
  box.innerHTML = matches.length ? matches.map(p => productCardHtml(p, 'schedule')).join('') : '<div class="muted">找不到符合的產品，請改用產品編號、條碼或名稱搜尋。</div>';
  if (hint) hint.textContent = value.trim() ? `找到 ${matches.length} 筆候選商品；零庫存商品仍會顯示。` : `共 ${scheduleProducts.length} 個商品，目前先顯示前 ${matches.length} 筆。`;
}
function renderScheduleProductPreview(product, confirmed){
  const box = document.getElementById('scheduleProductPreview');
  const hidden = document.getElementById('selectedScheduleImage');
  if (!box) return;
  const p = product || findProductById(pendingScheduleProductId);
  box.classList.toggle('is-confirmed', !!confirmed);
  if (!p) {
    box.textContent = '請先搜尋產品，按「選擇此產品」後，再按「確認帶入產品」。';
    if (hidden) hidden.value = '';
    return;
  }
  const images = Array.isArray(p.images) ? p.images : [];
  const stockName = p.warehouse_name || '-';
  let stockPosition = stockPositionLabel(p.shelf_code, p.warehouse_location);
  if (stockPosition === stockName) stockPosition = '';
  if(hidden) hidden.value = images[0] || '';
  box.innerHTML = `<div class="schedule-preview-grid">
    <div>${images[0] ? `<img class="schedule-preview-main zoomable" src="${images[0]}">` : ''}</div>
    <div><b>${p.id} / ${p.barcode || ''}</b><br>${p.title || ''}<br>規格：${[p.color,p.size,p.spec].filter(Boolean).join(' / ') || '-'}<br>庫存名稱：${escapeHtml(stockName)}${stockPosition ? '<br>貨架 / 倉位：' + escapeHtml(stockPosition) : ''}<br>總庫存：${p.stock_total || 0}｜預約：${scheduleProductReservedTotal(p)}｜已售：${p.stock_sold || 0}<br>${scheduleProductStockText(p)}${p.cloud_auction_locked ? '<br><b class="stock-warning">競標中鎖倉，禁止重複排程上架</b>' : ''}<br><b>${confirmed ? '狀態：已確認帶入排程' : '狀態：已選擇，尚未確認帶入'}</b><div class="schedule-thumbs">${images.map((img,i)=>`<img class="${i===0?'is-selected':''}" src="${img}" data-img="${img}">`).join('')}</div></div>
  </div>`;
  box.querySelectorAll('.schedule-thumbs img').forEach(img => img.addEventListener('click', () => {
    box.querySelectorAll('.schedule-thumbs img').forEach(x=>x.classList.remove('is-selected'));
    img.classList.add('is-selected');
    if(hidden) hidden.value = img.dataset.img || '';
    const main = box.querySelector('.schedule-preview-main');
    if(main) main.src = img.dataset.img || '';
  }));
}
const purchaseCostSettings = <?=json_encode($purchaseCostSettings, json_flags(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))?>;
let stockInRowSeq = 0;
function stockInRowHtml(p) {
  const idx = stockInRowSeq++;
  const productText = `${p.id || ''}${p.title ? ' - ' + p.title : ''}`;
  const specText = [p.color, p.size, p.spec].filter(Boolean).join(' / ') || '-';
  const stockName = p.warehouse_name || defaultWarehouseForDepartment(p.department || '電腦部門');
  const locationText = stockPositionLabel(p.shelf_code, p.warehouse_location) || '-';
  const unitCost = Number(p.purchase_source_unit_cost || p.cost || 0);
  return `<tr class="stock-in-line" data-product-id="${escapeHtml(p.id || '')}">
    <td><b>${escapeHtml(productText)}</b><br><small class="muted">目前 ${escapeHtml(scheduleProductStockText(p))}</small><input type="hidden" name="stock_items[${idx}][product_key]" value="${escapeHtml(p.id || p.barcode || '')}"></td>
    <td>${escapeHtml(p.barcode || '')}<br><small>${escapeHtml(specText)}</small></td>
    <td><input class="stock-line-qty" name="stock_items[${idx}][qty]" type="number" min="1" value="1" required></td>
    <td><input class="stock-line-cost" name="stock_items[${idx}][unit_cost]" type="number" min="0" step="0.01" value="${unitCost}"></td>
    <td class="stock-weight-col"><input class="stock-line-weight" name="stock_items[${idx}][weight_kg]" type="number" min="0" step="0.001" value="${Number(p.purchase_weight_kg || 0) || ''}" placeholder="kg"></td>
    <td class="stock-line-base">${escapeHtml(formatMoney(unitCost))}</td>
    <td class="stock-line-allocated">$0</td>
    <td class="stock-line-landed">${escapeHtml(formatMoney(unitCost))}</td>
    <td><select class="stock-row-warehouse" name="stock_items[${idx}][warehouse_name]" data-current="${escapeHtml(stockName)}"><option value="">請選擇倉別</option></select></td>
    <td><select class="stock-row-shelf" name="stock_items[${idx}][shelf_code]" data-current="${escapeHtml(p.shelf_code || '')}"><option value="">還沒放上去</option></select></td>
    <td><select class="stock-row-location" name="stock_items[${idx}][location]" data-current="${escapeHtml(p.warehouse_location || '')}"><option value="">還沒放上去</option><option value="上層">上層</option><option value="下層">下層</option></select></td>
    <td><input name="stock_items[${idx}][note]" placeholder="備註"></td>
    <td><button type="button" class="danger small remove-stock-line">移除</button><br><small class="muted">${escapeHtml(locationText)}</small></td>
  </tr>`;
}
function formatMoney(value) {
  return '$' + Math.round(Number(value || 0)).toLocaleString('zh-TW');
}
function purchaseWarehouseFeeUnit(warehouse) {
  const value = String(warehouse || '');
  if (value.includes('中國') || value.includes('東莞')) return Math.max(0, Number(purchaseCostSettings.china_warehouse_fee || 30));
  if (value.includes('台灣') || value.includes('寶輝') || value.includes('電腦')) return Math.max(0, Number(purchaseCostSettings.taiwan_warehouse_fee || 20));
  return 0;
}
function isChinaWarehouse(warehouse) {
  const value = String(warehouse || '');
  return value.includes('中國') || value.includes('東莞');
}
function refreshStockDocumentTotals() {
  const rows = Array.from(document.querySelectorAll('#stockInRows .stock-in-line'));
  const rate = Math.max(0.0001, Number(document.getElementById('stockExchangeRate')?.value || 1));
  const method = document.getElementById('stockAllocationMethod')?.value || 'quantity';
  const source = document.getElementById('stockSource')?.value || '其他';
  const prepared = rows.map(row => {
    const qty = Math.max(0, Number(row.querySelector('.stock-line-qty')?.value || 0));
    const cost = Math.max(0, Number(row.querySelector('.stock-line-cost')?.value || 0));
    const weight = Math.max(0, Number(row.querySelector('.stock-line-weight')?.value || 0));
    const warehouse = row.querySelector('.stock-row-warehouse')?.value || '';
    return {row, qty, weight, warehouse, isChina: isChinaWarehouse(warehouse), base: qty * cost * rate};
  });
  const lineTotal = prepared.reduce((sum, line) => sum + line.base, 0);
  const quantityTotal = prepared.reduce((sum, line) => sum + line.qty, 0);
  const weightTotal = prepared.reduce((sum, line) => sum + (line.isChina ? 0 : line.weight), 0);
  const customsCountInput = document.getElementById('stockCustomsPackageCount');
  const declaredCount = Math.max(0, Number(customsCountInput?.value || 0));
  const costBasisQty = declaredCount > 0 ? declaredCount : Math.max(1, quantityTotal);
  const discount = Math.max(0, Number(document.getElementById('stockDocumentDiscount')?.value || 0));
  const tax = Math.max(0, Number(document.getElementById('stockDocumentTax')?.value || 0));
  const customsInput = document.getElementById('stockCustomsFee');
  const customs = source === '豪鴻' ? 0 : Math.max(0, Number(customsInput?.value || 0));
  const chinaFreight = Math.max(0, Number(document.getElementById('stockChinaFreight')?.value || 0));
  const taiwanShipping = Math.max(0, Number(document.getElementById('stockTaiwanShipping')?.value || 0));
  const shipping = Math.max(0, Number(document.getElementById('stockDocumentShippingFee')?.value || 0));
  const other = Math.max(0, Number(document.getElementById('stockDocumentOtherFee')?.value || 0));
  const useLingzanzan = source === '拼多多' || source === '豪鴻';
  const otherWeightTotal = method === 'amount' ? lineTotal : quantityTotal;
  let total = 0;
  let extraPerItem = 0;
  if (source === '拼多多') extraPerItem = costBasisQty > 0 ? taiwanShipping / costBasisQty : 0;
  else if (source === '豪鴻' && weightTotal <= 0) extraPerItem = costBasisQty > 0 ? shipping / costBasisQty : 0;
  prepared.forEach(line => {
    const handlingUnit = purchaseWarehouseFeeUnit(line.warehouse);
    let extraTotal = 0;
    if (useLingzanzan) {
      if (!line.isChina) {
        if (source === '拼多多') {
          extraTotal = extraPerItem * line.qty;
        } else if (source === '豪鴻') {
          if (shipping > 0 && weightTotal > 0) extraTotal = shipping * (line.weight / weightTotal);
          else if (shipping <= 0 && line.weight > 0) extraTotal = line.weight * 8 * rate;
          else extraTotal = extraPerItem * line.qty;
        }
      }
    } else {
      const shareWeight = method === 'amount' ? line.base : line.qty;
      const sharedPool = tax + customs + chinaFreight + taiwanShipping + shipping + other - discount;
      extraTotal = otherWeightTotal > 0 ? sharedPool * (shareWeight / otherWeightTotal) : 0;
    }
    const warehouseFee = handlingUnit * line.qty;
    const allocated = extraTotal + warehouseFee;
    const landedTotal = Math.max(0, line.base + allocated);
    const landedUnit = line.qty > 0 ? landedTotal / line.qty : 0;
    total += landedTotal;
    const baseCell = line.row.querySelector('.stock-line-base');
    const allocatedCell = line.row.querySelector('.stock-line-allocated');
    const landedCell = line.row.querySelector('.stock-line-landed');
    if (baseCell) baseCell.textContent = formatMoney(line.base);
    if (allocatedCell) allocatedCell.textContent = `${allocated >= 0 ? '+' : '-'}${formatMoney(Math.abs(allocated))}`;
    if (landedCell) landedCell.innerHTML = `<b>${escapeHtml(formatMoney(landedUnit))}</b><br><small>${escapeHtml(formatMoney(landedTotal))}</small>`;
  });
  const lineBox = document.getElementById('stockLineTotal');
  const totalBox = document.getElementById('stockDocumentTotal');
  const formulaBox = document.getElementById('stockDocumentFormula');
  if (lineBox) lineBox.textContent = formatMoney(lineTotal);
  if (totalBox) totalBox.textContent = formatMoney(total);
  if (formulaBox) {
    const handlingTw = Number(purchaseCostSettings.taiwan_warehouse_fee || 20);
    const handlingCn = Number(purchaseCostSettings.china_warehouse_fee || 30);
    if (source === '拼多多') {
      formulaBox.textContent = `拼多多：人民幣 × ${rate} + 台灣快遞 ${formatMoney(taiwanShipping)} ÷ 關稅登記 ${costBasisQty} 件＝每件額外 ${formatMoney(extraPerItem)}（關稅 ${formatMoney(customs)} 只作核對；商品價已含中國運費）。台灣倉人事 +${handlingTw}／東莞倉免關稅只加人事 +${handlingCn}。`;
    } else if (source === '豪鴻') {
      const perKg = weightTotal > 0 && shipping > 0 ? shipping / weightTotal : 0;
      formulaBox.textContent = `豪鴻：免稅。人民幣 × ${rate} + 整批運費 ${formatMoney(shipping)} ${weightTotal > 0 ? '依計費重量 ' + weightTotal + ' kg 分攤（每公斤約 ' + formatMoney(perKg) + '）' : '÷ ' + costBasisQty + ' 件'} + 倉別人事。東莞倉免集運分攤，只加人事 +${handlingCn}。`;
    } else {
      formulaBox.textContent = '其他來源：來源單價 × 匯率 − 折扣 + 稅額 + 關稅 + 中國運費 + 台灣快遞 + 整批運費 + 其他費用 + 倉別人事。';
    }
  }
}
function addStockInRow(p) {
  const tbody = document.getElementById('stockInRows');
  if (!tbody || !p) return;
  const isFirstLine = !tbody.querySelector('.stock-in-line');
  tbody.querySelector('.empty-stock-row')?.remove();
  tbody.insertAdjacentHTML('beforeend', stockInRowHtml(p));
  const row = tbody.querySelector('.stock-in-line:last-child');
  bindWarehouseSelectElements(
    row?.querySelector('.stock-row-warehouse'),
    row?.querySelector('.stock-row-shelf'),
    row?.querySelector('.stock-row-location'),
    {
      warehouse: '請選擇倉別',
      shelf: '請先選倉別',
      location: '還沒放上去'
    },
    p.department || '電腦部門'
  );
  if (isFirstLine) {
    const source = document.getElementById('stockSource');
    const currency = document.getElementById('stockCurrency');
    const rate = document.getElementById('stockExchangeRate');
    if (source && p.purchase_source) {
      source.value = p.purchase_source;
      source.dispatchEvent(new Event('change', {bubbles:true}));
    }
    if (currency && p.purchase_source_currency) currency.value = p.purchase_source_currency;
    if (rate && Number(p.purchase_exchange_rate || 0) > 0) rate.value = String(p.purchase_exchange_rate);
  }
  const sourceNow = document.getElementById('stockSource')?.value || '其他';
  document.querySelectorAll('.stock-weight-col').forEach(el => { el.hidden = sourceNow !== '豪鴻'; });
  refreshStockDocumentTotals();
}
function setStockProduct(p) {
  addStockInRow(p);
  const input = document.getElementById('stockProductSearch');
  const results = document.getElementById('stockProductResults');
  if (input) { input.value = ''; input.focus(); }
  if (results) results.innerHTML = '';
}
function renderStockProductResults() {
  const input = document.getElementById('stockProductSearch');
  const box = document.getElementById('stockProductResults');
  if (!input || !box) return;
  const matches = productSearchMatches(input.value, 10);
  box.innerHTML = matches.length ? matches.map(p => productCardHtml(p, 'stock')).join('') : '';
}
let salesOutRowSeq = <?= (int)($salesOutRowIndex ?? 0) ?>;
function salesOutRowHtml(p) {
  const idx = salesOutRowSeq++;
  const available = scheduleProductAvailable(p);
  const productText = `${p.id || ''}${p.title ? ' - ' + p.title : ''}`;
  const specText = [p.color, p.size, p.spec].filter(Boolean).join(' / ') || '-';
  const stockName = p.warehouse_name || '-';
  const positionText = stockPositionLabel(p.shelf_code, p.warehouse_location) || '-';
  const unitPrice = Number(p.sale_price || p.reference_price || p.cost || 0);
  return `<tr class="sales-out-line" data-product-id="${escapeHtml(p.id || '')}">
    <td><b>${escapeHtml(productText)}</b><input type="hidden" name="sales_items[${idx}][product_key]" value="${escapeHtml(p.id || p.barcode || '')}"></td>
    <td>${escapeHtml(p.barcode || '')}<br><small>${escapeHtml(specText)}</small></td>
    <td>${available}</td>
    <td><input class="sales-line-qty" name="sales_items[${idx}][qty]" type="number" min="1" max="${available}" value="1" required></td>
    <td><input class="sales-line-price" name="sales_items[${idx}][unit_price]" type="number" min="0" step="0.01" value="${unitPrice}"></td>
    <td class="sales-line-subtotal">${escapeHtml(formatMoney(unitPrice))}</td>
    <td>${escapeHtml(stockName)}</td>
    <td>${escapeHtml(positionText)}</td>
    <td><input name="sales_items[${idx}][note]" placeholder="備註"></td>
    <td><button type="button" class="danger small remove-sales-line">移除</button></td>
  </tr>`;
}
function refreshSalesDocumentTotals() {
  let lineTotal = 0;
  document.querySelectorAll('#salesOutRows .sales-out-line').forEach(row => {
    const qty = Math.max(0, Number(row.querySelector('.sales-line-qty')?.value || 0));
    const price = Math.max(0, Number(row.querySelector('.sales-line-price')?.value || 0));
    const subtotal = qty * price;
    lineTotal += subtotal;
    const cell = row.querySelector('.sales-line-subtotal');
    if (cell) cell.textContent = formatMoney(subtotal);
  });
  const discount = Math.max(0, Number(document.getElementById('salesDocumentDiscount')?.value || 0));
  const tax = Math.max(0, Number(document.getElementById('salesDocumentTax')?.value || 0));
  const shipping = Math.max(0, Number(document.getElementById('salesDocumentShippingFee')?.value || 0));
  const other = Math.max(0, Number(document.getElementById('salesDocumentOtherFee')?.value || 0));
  const total = Math.max(0, lineTotal - discount + tax + shipping + other);
  const lineBox = document.getElementById('salesLineTotal');
  const totalBox = document.getElementById('salesDocumentTotal');
  if (lineBox) lineBox.textContent = formatMoney(lineTotal);
  if (totalBox) totalBox.textContent = formatMoney(total);
}
function addSalesOutRow(p) {
  const tbody = document.getElementById('salesOutRows');
  if (!tbody || !p) return;
  if (scheduleProductAvailable(p) <= 0) return;
  tbody.querySelector('.empty-sales-row')?.remove();
  tbody.insertAdjacentHTML('beforeend', salesOutRowHtml(p));
  refreshSalesDocumentTotals();
}
function setSalesProduct(p) {
  addSalesOutRow(p);
  const input = document.getElementById('salesProductSearch');
  const results = document.getElementById('salesProductResults');
  if (input) { input.value = ''; input.focus(); }
  if (results) results.innerHTML = '';
}
function renderSalesProductResults() {
  const input = document.getElementById('salesProductSearch');
  const box = document.getElementById('salesProductResults');
  if (!input || !box) return;
  const matches = productSearchMatches(input.value, 10);
  box.innerHTML = matches.length ? matches.map(p => productCardHtml(p, 'sales')).join('') : '';
}
document.getElementById('scheduleProductSearch')?.addEventListener('input', () => {
  pendingScheduleProductId = '';
  const hidden = document.getElementById('scheduleProductKey');
  const confirm = document.getElementById('confirmScheduleProduct');
  const text = document.getElementById('schedulePendingProductText');
  if (hidden) hidden.value = '';
  if (confirm) confirm.disabled = true;
  if (text) text.textContent = '尚未選擇產品。';
  renderScheduleProductResults();
  renderScheduleProductPreview(null, false);
});
document.getElementById('scheduleProductSearch')?.addEventListener('focus', renderScheduleProductResults);
document.getElementById('scheduleProductSearch')?.addEventListener('keydown', (event) => {
  if (event.key !== 'Enter') return;
  event.preventDefault();
  const product = productSearchMatches(event.currentTarget.value, 1)[0];
  if (product) setScheduleProduct(product);
});
document.getElementById('confirmScheduleProduct')?.addEventListener('click', confirmScheduleProduct);
document.getElementById('stockProductSearch')?.addEventListener('input', renderStockProductResults);
document.getElementById('salesProductSearch')?.addEventListener('input', renderSalesProductResults);
document.getElementById('stockProductSearch')?.addEventListener('keydown', (event) => {
  if (event.key !== 'Enter') return;
  event.preventDefault();
  const input = event.currentTarget;
  const product = findScheduleProduct(input.value) || productSearchMatches(input.value, 1)[0];
  if (product) setStockProduct(product);
});
document.getElementById('salesProductSearch')?.addEventListener('keydown', (event) => {
  if (event.key !== 'Enter') return;
  event.preventDefault();
  const product = findScheduleProduct(event.currentTarget.value) || productSearchMatches(event.currentTarget.value, 1)[0];
  if (product) setSalesProduct(product);
});
document.addEventListener('click', (event) => {
  const card = event.target.closest?.('.product-result-card');
  if (!card) return;
  const p = scheduleProducts.find(item => String(item.id || '') === String(card.dataset.productId || ''));
  if (!p) return;
  if (card.dataset.mode === 'schedule') { setScheduleProduct(p); }
  if (card.dataset.mode === 'stock') { setStockProduct(p); const box = document.getElementById('stockProductResults'); if (box) box.innerHTML = ''; }
  if (card.dataset.mode === 'sales') { setSalesProduct(p); const box = document.getElementById('salesProductResults'); if (box) box.innerHTML = ''; }
});
renderScheduleProductResults();
document.addEventListener('click', (event) => {
  const remove = event.target.closest?.('.remove-stock-line');
  if (!remove) return;
  remove.closest('tr')?.remove();
  const tbody = document.getElementById('stockInRows');
  if (tbody && !tbody.querySelector('.stock-in-line')) {
    tbody.innerHTML = '<tr class="empty-stock-row"><td colspan="13" class="muted">請先掃描條碼或搜尋產品加入明細。</td></tr>';
  }
  refreshStockDocumentTotals();
});
document.addEventListener('click', (event) => {
  const remove = event.target.closest?.('.remove-sales-line');
  if (!remove) return;
  remove.closest('tr')?.remove();
  const tbody = document.getElementById('salesOutRows');
  if (tbody && !tbody.querySelector('.sales-out-line')) {
    tbody.innerHTML = '<tr class="empty-sales-row"><td colspan="10" class="muted">請先掃描條碼或搜尋產品加入銷售明細。</td></tr>';
  }
  refreshSalesDocumentTotals();
});
document.getElementById('salesOutRows')?.addEventListener('input', event => {
  if (event.target.closest?.('.sales-line-qty,.sales-line-price')) refreshSalesDocumentTotals();
});
['salesDocumentDiscount','salesDocumentTax','salesDocumentShippingFee','salesDocumentOtherFee'].forEach(id => {
  document.getElementById(id)?.addEventListener('input', refreshSalesDocumentTotals);
});
refreshSalesDocumentTotals();
document.getElementById('stockInRows')?.addEventListener('input', event => {
  if (event.target.closest?.('.stock-line-qty,.stock-line-cost,.stock-line-weight')) refreshStockDocumentTotals();
});
document.getElementById('stockInRows')?.addEventListener('change', event => {
  if (event.target.closest?.('.stock-row-warehouse')) refreshStockDocumentTotals();
});
['stockDocumentDiscount','stockDocumentTax','stockCustomsFee','stockChinaFreight','stockTaiwanShipping','stockDocumentShippingFee','stockDocumentOtherFee','stockExchangeRate','stockAllocationMethod','stockCustomsPackageCount'].forEach(id => {
  document.getElementById(id)?.addEventListener('input', refreshStockDocumentTotals);
  document.getElementById(id)?.addEventListener('change', refreshStockDocumentTotals);
});
document.getElementById('stockCurrency')?.addEventListener('change', event => {
  const rateInput = document.getElementById('stockExchangeRate');
  if (rateInput) rateInput.value = event.currentTarget.value === 'CNY' ? String(purchaseCostSettings.rmb_fixed_rate || 5) : '1';
  refreshStockDocumentTotals();
});
document.getElementById('stockSource')?.addEventListener('change', event => {
  const source = event.currentTarget.value;
  const currency = document.getElementById('stockCurrency');
  const rate = document.getElementById('stockExchangeRate');
  const customs = document.getElementById('stockCustomsFee');
  const hint = document.getElementById('stockCustomsHint');
  const chinaHint = document.getElementById('stockChinaFreightHint');
  const taiwanHint = document.getElementById('stockTaiwanHint');
  const shippingHint = document.getElementById('stockShippingHint');
  const countHint = document.getElementById('stockCustomsCountHint');
  const allocWrap = document.getElementById('stockAllocationMethodWrap');
  const chinaFreight = document.getElementById('stockChinaFreight');
  if (source === '拼多多' || source === '豪鴻') {
    if (currency) currency.value = 'CNY';
    if (rate) rate.value = String(purchaseCostSettings.rmb_fixed_rate || 5);
  }
  if (customs) {
    customs.disabled = source === '豪鴻';
    if (source === '豪鴻') customs.value = '0';
  }
  if (chinaFreight) {
    chinaFreight.disabled = source === '拼多多' || source === '豪鴻';
    if (source === '拼多多' || source === '豪鴻') chinaFreight.value = '0';
  }
  if (allocWrap) allocWrap.hidden = source !== '其他';
  document.querySelectorAll('[data-stock-other-fee]').forEach(el => { el.hidden = source !== '其他'; });
  document.querySelectorAll('.stock-weight-col').forEach(el => { el.hidden = source !== '豪鴻'; });
  if (hint) {
    hint.textContent = source === '豪鴻'
      ? '豪鴻免稅，系統固定按 0 元計算，不計入成本。'
      : (source === '拼多多' ? '關稅只作核對，不計入產品成本。' : '輸入整批實際關稅後依選定方式分攤。');
  }
  if (chinaHint) chinaHint.textContent = source === '拼多多' ? '拼多多商品價已含中國運費，不計入成本。' : (source === '豪鴻' ? '豪鴻不另計中國段運費。' : '可計入到岸成本。');
  if (taiwanHint) taiwanHint.textContent = source === '拼多多' ? '拼多多：這筆 ÷ 關稅登記件數＝每件額外成本。' : (source === '豪鴻' ? '豪鴻不使用台灣快遞欄，請填整批運費。' : '可計入到岸成本。');
  if (shippingHint) shippingHint.textContent = source === '豪鴻' ? '豪鴻：依各列計費重量分攤；沒填重量則依登記件數平均。' : (source === '拼多多' ? '拼多多不使用整批運費，請填台灣快遞實收。' : '可計入到岸成本。');
  if (countHint) countHint.textContent = source === '拼多多' ? '拼多多成本分母。空白則用本單數量。' : (source === '豪鴻' ? '沒填計費重量時，整批運費改用這個件數平均。' : '其他來源不使用此欄。');
  refreshStockDocumentTotals();
});
document.getElementById('stockSource')?.dispatchEvent(new Event('change'));
document.querySelectorAll('.quick-close').forEach(btn => btn.addEventListener('click', () => {
  const input = document.getElementById('closeTimeInput');
  if (input) input.value = btn.dataset.time || '';
}));

function renumberScheduleJobs() {
  document.querySelectorAll('[data-schedule-job-row]').forEach((row, index) => {
    const badge = row.querySelector('.schedule-job-index');
    if (badge) badge.textContent = String(index + 1);
  });
}
function cloneScheduleJobRow() {
  const box = document.getElementById('scheduleJobRows');
  const first = box?.querySelector('[data-schedule-job-row]');
  if (!box || !first) return;
  const row = first.cloneNode(true);
  row.querySelectorAll('input').forEach((input) => {
    if (input.type === 'number') input.value = '1';
    if (input.type === 'date') {
      const lastDate = Array.from(box.querySelectorAll('input[name="job_date[]"]')).pop()?.value || input.value;
      const d = lastDate ? new Date(lastDate + 'T00:00:00') : new Date();
      d.setDate(d.getDate() + 1);
      const next = d.toISOString().slice(0, 10);
      input.value = next;
    }
    if (input.name === 'job_publish_time[]') input.value = '20:00';
    if (input.name === 'job_close_time[]') input.value = '23:59';
  });
  const publishDate = row.querySelector('input[name="job_date[]"]')?.value || '';
  const closeDate = row.querySelector('input[name="job_close_date[]"]');
  if (closeDate) closeDate.value = publishDate;
  box.appendChild(row);
  renumberScheduleJobs();
}
document.getElementById('addScheduleJob')?.addEventListener('click', cloneScheduleJobRow);
document.addEventListener('click', (event) => {
  const closeBtn = event.target.closest?.('.schedule-row-close');
  if (closeBtn) {
    const row = closeBtn.closest('[data-schedule-job-row]');
    const input = row?.querySelector('input[name="job_close_time[]"]');
    if (input) input.value = closeBtn.dataset.time || '23:59';
    return;
  }
  const removeBtn = event.target.closest?.('.remove-schedule-job');
  if (removeBtn) {
    const rows = document.querySelectorAll('[data-schedule-job-row]');
    if (rows.length <= 1) {
      alert('至少要保留一筆工作排程。');
      return;
    }
    removeBtn.closest('[data-schedule-job-row]')?.remove();
    renumberScheduleJobs();
  }
});
document.addEventListener('change', (event) => {
  const dateInput = event.target.closest?.('input[name="job_date[]"]');
  if (!dateInput) return;
  const row = dateInput.closest('[data-schedule-job-row]');
  const closeDate = row?.querySelector('input[name="job_close_date[]"]');
  if (closeDate && !closeDate.dataset.touched) closeDate.value = dateInput.value;
});
document.addEventListener('input', (event) => {
  const closeDate = event.target.closest?.('input[name="job_close_date[]"]');
  if (closeDate) closeDate.dataset.touched = '1';
});
renumberScheduleJobs();


function productPreviewImg(file, className) {
  const url = URL.createObjectURL(file);
  return `<span><img class="${className || ''} zoomable" src="${url}" alt="${file.name}"><div class="product-upload-name">${file.name}</div></span>`;
}
function renderProductUploadPreview() {
  const mainInput = document.querySelector('#productMasterForm input[name="image"]');
  const photosInput = document.querySelector('#productMasterForm input[name="photos[]"]');
  const panel = document.getElementById('productUploadPreview');
  const mainBox = document.getElementById('productUploadMain');
  const gallery = document.getElementById('productUploadGallery');
  if (!panel || !mainBox || !gallery) return;
  const mainFiles = mainInput?.files ? Array.from(mainInput.files) : [];
  const photoFiles = photosInput?.files ? Array.from(photosInput.files) : [];
  panel.hidden = mainFiles.length === 0 && photoFiles.length === 0;
  mainBox.innerHTML = mainFiles.length ? productPreviewImg(mainFiles[0], 'product-upload-main-img') : '<span class="muted">尚未選主圖</span>';
  gallery.innerHTML = photoFiles.length ? photoFiles.map(file => productPreviewImg(file, 'product-upload-thumb')).join('') : '<span class="muted">尚未選其他照片</span>';
}
async function normalizeImageFile(file) {
  if (!file) return null;
  const type = String(file.type || '').toLowerCase();
  if (type === 'image/jpeg' || type === 'image/png' || type === 'image/webp') return file;
  try {
    const bitmap = await createImageBitmap(file);
    const max = 2200;
    const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9));
    if (!blob) return file;
    const base = String(file.name || 'photo').replace(/\.[^.]+$/, '') || 'photo';
    return new File([blob], base + '.jpg', { type: 'image/jpeg' });
  } catch (error) {
    return file;
  }
}
async function normalizeImageFiles(fileList) {
  const files = [];
  for (const file of Array.from(fileList || [])) {
    const normalized = await normalizeImageFile(file);
    if (normalized) files.push(normalized);
  }
  return files;
}
function findNamedFileInput(source, name) {
  const selector = 'input[name="' + String(name).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]';
  const form = source.closest('form');
  return (form && form.querySelector(selector)) || document.querySelector(selector);
}
function assignFilesToInput(input, files, append) {
  if (!input || typeof DataTransfer === 'undefined') return;
  const transfer = new DataTransfer();
  if (append) Array.from(input.files || []).forEach(file => transfer.items.add(file));
  files.forEach(file => transfer.items.add(file));
  input.files = transfer.files;
  input.dispatchEvent(new Event('change', { bubbles: true }));
  const preview = input.closest('form')?.querySelector('[data-photo-preview]');
  if (preview) {
    const allFiles = [];
    input.closest('form').querySelectorAll('input.photo-native-input').forEach(item => {
      Array.from(item.files || []).forEach(file => allFiles.push(file));
    });
    preview.hidden = allFiles.length === 0;
    preview.innerHTML = allFiles.map(file => '<img src="' + URL.createObjectURL(file) + '" alt="' + file.name + '">').join('');
  }
}
async function uploadQuickProductPhotos(productId, files, role) {
  if (!productId || !files.length) return;
  const form = new FormData();
  form.append('action', 'quick_product_images');
  form.append('product_id', productId);
  if (role === 'extra') files.forEach(file => form.append('quick_extra_images[]', file, file.name));
  else form.append('quick_main_image', files[0], files[0].name);
  const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: form, credentials: 'same-origin' });
  if (!response.ok) throw new Error('上傳失敗');
  window.location.reload();
}
document.addEventListener('change', async event => {
  const picker = event.target.closest?.('input[data-photo-assign]');
  const quick = event.target.closest?.('input[data-quick-photo-product]');
  if (picker) {
    const files = await normalizeImageFiles(picker.files);
    picker.value = '';
    if (!files.length) return;
    const target = findNamedFileInput(picker, picker.dataset.photoAssign || '');
    if (!target) return;
    assignFilesToInput(target, files, picker.dataset.photoAppend === '1');
    return;
  }
  if (quick) {
    const files = await normalizeImageFiles(quick.files);
    quick.value = '';
    if (!files.length) return;
    const label = quick.closest('label');
    const original = label ? label.childNodes[0].textContent : '';
    if (label) label.childNodes[0].textContent = '上傳中...';
    try {
      await uploadQuickProductPhotos(quick.dataset.quickPhotoProduct || '', files, quick.dataset.quickPhotoRole || 'main');
    } catch (error) {
      if (label) label.childNodes[0].textContent = original || '拍照上傳';
      alert(error.message || '上傳失敗，請再拍一次。');
    }
  }
});
document.querySelector('#productMasterForm input[name="image"]')?.addEventListener('change', renderProductUploadPreview);
document.querySelector('#productMasterForm input[name="photos[]"]')?.addEventListener('change', renderProductUploadPreview);

function customerDocumentRows(customerValue, sourceRows) {
  const query = normalizeProductSearch(customerValue);
  if (!query) return [];
  const rows = Array.isArray(sourceRows) ? sourceRows : [];
  const exact = rows.filter(row => normalizeProductSearch(row.customer) === query);
  const matches = exact.length ? exact : rows.filter(row => normalizeProductSearch(row.customer).includes(query));
  return matches.slice().sort((a, b) => `${a.date || ''}${a.document_no || ''}`.localeCompare(`${b.date || ''}${b.document_no || ''}`, 'zh-Hant'));
}
function customerDocumentOption(row, inputName) {
  return `<label class="customer-document-option"><input type="checkbox" name="${inputName}" value="${escapeHtml(row.schedule_id || '')}" data-outstanding="${Number(row.outstanding || 0)}"><span><b>${escapeHtml(row.date || '-')}</b><small>${escapeHtml(row.document_no || '')}</small></span><span><b>${escapeHtml(row.product || '-')}</b><small>${escapeHtml([row.delivery_no,row.invoice_no].filter(Boolean).join(' / '))}</small></span><span>應收<br><b>${escapeHtml(String(row.receivable || 0))}</b></span><span>已收<br><b>${escapeHtml(String(row.paid || 0))}</b></span><strong>未收<br>${escapeHtml(String(row.outstanding || 0))}</strong></label>`;
}
function renderCustomerDocumentPicker(inputId, boxId, inputName, sourceRows) {
  const input = document.getElementById(inputId);
  const box = document.getElementById(boxId);
  if (!input || !box) return;
  const rows = customerDocumentRows(input.value, sourceRows);
  box.innerHTML = rows.length ? rows.map(row => customerDocumentOption(row, inputName)).join('') : '<div class="customer-document-empty">找不到這位客戶的未結單據。</div>';
}
document.getElementById('receiptCustomerName')?.addEventListener('input', () => renderCustomerDocumentPicker('receiptCustomerName', 'receiptCustomerDocuments', 'document_nos[]', window.receiptDocumentCandidates));
document.getElementById('receiptCustomerName')?.addEventListener('change', () => renderCustomerDocumentPicker('receiptCustomerName', 'receiptCustomerDocuments', 'document_nos[]', window.receiptDocumentCandidates));
document.getElementById('billingCustomerName')?.addEventListener('input', () => renderCustomerDocumentPicker('billingCustomerName', 'billingCustomerDocuments', 'billing_schedule_ids[]', window.receiptDocumentCandidates));
document.getElementById('billingCustomerName')?.addEventListener('change', () => renderCustomerDocumentPicker('billingCustomerName', 'billingCustomerDocuments', 'billing_schedule_ids[]', window.receiptDocumentCandidates));
document.getElementById('badDebtCustomerName')?.addEventListener('input', () => renderCustomerDocumentPicker('badDebtCustomerName', 'badDebtCustomerDocuments', 'bad_debt_schedule_ids[]', window.badDebtDocumentCandidates));
document.getElementById('badDebtCustomerName')?.addEventListener('change', () => renderCustomerDocumentPicker('badDebtCustomerName', 'badDebtCustomerDocuments', 'bad_debt_schedule_ids[]', window.badDebtDocumentCandidates));
document.getElementById('receiptCustomerDocuments')?.addEventListener('change', event => {
  if (!event.target.matches('input[type="checkbox"]')) return;
  const checked = Array.from(event.currentTarget.querySelectorAll('input[type="checkbox"]:checked'));
  const amountInput = document.querySelector('#finance-collection form input[name="amount"]');
  if (amountInput && Number(amountInput.value || 0) <= 0) amountInput.value = checked.reduce((sum, input) => sum + Number(input.dataset.outstanding || 0), 0);
});
document.querySelectorAll('.print-billing-request').forEach(button => button.addEventListener('click', () => {
  const card = document.getElementById(`billing-request-${button.dataset.id || ''}`);
  if (!card) return;
  card.open = true;
  card.classList.add('is-print-target');
  document.body.classList.add('billing-print');
  window.print();
  card.classList.remove('is-print-target');
  document.body.classList.remove('billing-print');
}));

document.getElementById('printReconcileStatement')?.addEventListener('click', () => {
  document.body.classList.add('reconcile-print');
  window.print();
  window.setTimeout(() => document.body.classList.remove('reconcile-print'), 500);
});
window.addEventListener('afterprint', () => document.body.classList.remove('reconcile-print'));
window.addEventListener('afterprint', () => {
  document.body.classList.remove('billing-print');
  document.querySelectorAll('.billing-request-card.is-print-target').forEach(card => card.classList.remove('is-print-target'));
});
document.getElementById('printDocumentCenter')?.addEventListener('click', () => {
  document.body.classList.add('document-print');
  window.print();
  window.setTimeout(() => document.body.classList.remove('document-print'), 500);
});
window.addEventListener('afterprint', () => document.body.classList.remove('document-print'));
document.getElementById('exportReconcileCsv')?.addEventListener('click', () => {
  const table = document.getElementById('reconcileDetailTable');
  if (!table) return;
  const rows = Array.from(table.querySelectorAll('tr')).map(row => Array.from(row.querySelectorAll('th,td')).slice(0, 14).map(cell => `"${String(cell.innerText || '').replace(/"/g, '""').replace(/\s+/g, ' ').trim()}"`).join(','));
  const csv = '\uFEFF' + rows.join('\r\n');
  const link = document.createElement('a');
  link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  link.download = `寶輝科技-銷售收款對帳單-${new Date().toISOString().slice(0, 10)}.csv`;
  link.click();
  URL.revokeObjectURL(link.href);
});

document.body.insertAdjacentHTML('beforeend','<div class="image-lightbox" id="imageLightbox"><img alt=""></div>');
document.addEventListener('click', (event) => {
  const target = event.target;
  if (target?.classList?.contains('zoomable') || target?.classList?.contains('thumb')) {
    const box = document.getElementById('imageLightbox');
    box.querySelector('img').src = target.src;
    box.classList.add('is-open');
  } else if (target?.id === 'imageLightbox') {
    target.classList.remove('is-open');
  }
});

document.addEventListener('change', (event) => {
  const select = event.target.closest?.('.logistics-company-select');
  if (!select) return;
  const form = select.closest('form');
  const feeInput = form?.querySelector('.shipping-fee-input');
  const selected = select.options[select.selectedIndex];
  const fee = Number(selected?.dataset?.fee || 0);
  if (!feeInput || !Number.isFinite(fee)) return;
  feeInput.value = String(Math.max(0, fee));
});

document.querySelectorAll('.edit-settlement').forEach((btn) => btn.addEventListener('click', () => {
  showOpsTab('settlement-edit');
  setTimeout(() => document.querySelector('#settle-' + btn.dataset.id)?.scrollIntoView({behavior:'smooth', block:'start'}), 50);
}));

document.querySelectorAll('.batch-member-picker').forEach((select) => {
  select.addEventListener('change', () => {
    const opt = select.options[select.selectedIndex];
    const form = select.closest('form');
    if (!form || !opt) return;
    ['name','facebook','phone','address'].forEach((field) => {
      const input = form.querySelector(`[data-batch-member-field="${field}"]`);
      const value = opt.dataset[field] || '';
      if (input && value) input.value = value;
    });
  });
});


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
document.querySelectorAll('.copy-fb-box').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const area = btn.parentElement?.querySelector('textarea');
    const text = area?.value || '';
    try { await navigator.clipboard.writeText(text); btn.textContent = '已複製'; setTimeout(() => btn.textContent = '複製', 1200); }
    catch (e) { area?.select(); document.execCommand('copy'); }
  });
});
document.querySelectorAll('.copy-schedule-qa').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const text = btn.closest('form')?.querySelector('.schedule-qa-draft')?.value || '';
    try { await navigator.clipboard.writeText(text); btn.textContent = '已複製'; setTimeout(() => btn.textContent = '複製問答包', 1200); }
    catch (e) { btn.closest('form')?.querySelector('.schedule-qa-draft')?.select(); document.execCommand('copy'); }
  });
});
document.querySelectorAll('.schedule-post-set-pick').forEach((select) => {
  select.addEventListener('change', () => {
    let payload = {};
    try { payload = JSON.parse(select.getAttribute('data-payload') || '{}') || {}; } catch (e) { payload = {}; }
    const picked = payload[select.value] || {};
    const form = select.closest('form');
    if (form && form.querySelector('.schedule-listing-draft')) form.querySelector('.schedule-listing-draft').value = picked.listing || '';
    if (form && form.querySelector('.schedule-qa-draft')) form.querySelector('.schedule-qa-draft').value = picked.qa || '';
  });
});
(function bindPostReplyEditors() {
  const qaHtml = '<div class="post-reply-qa-row"><label>問法<input name="qa_ask[]" placeholder="有現貨嗎？"></label><label>關鍵字<input name="qa_aliases[]" placeholder="現貨,有貨,庫存"></label><label class="wide">回答<textarea name="qa_answer[]" rows="2"></textarea></label><button type="button" class="danger small post-reply-qa-remove">刪這則</button></div>';
  document.querySelectorAll('.post-reply-qa-add').forEach((btn) => {
    btn.addEventListener('click', () => {
      const wrap = btn.closest('.post-reply-qa-block')?.querySelector('[data-qa-rows]');
      if (!wrap) return;
      wrap.insertAdjacentHTML('beforeend', qaHtml);
    });
  });
  document.addEventListener('click', (event) => {
    const remove = event.target.closest?.('.post-reply-qa-remove');
    if (!remove) return;
    const rows = remove.closest('[data-qa-rows]');
    const row = remove.closest('.post-reply-qa-row');
    if (!rows || !row) return;
    if (rows.querySelectorAll('.post-reply-qa-row').length <= 1) {
      row.querySelectorAll('input,textarea').forEach((el) => { el.value = ''; });
      return;
    }
    row.remove();
  });
  function fillTemplate(template, tokens) {
    let out = String(template || '');
    Object.keys(tokens || {}).forEach((key) => {
      out = out.split('{{' + key + '}}').join(tokens[key] == null ? '' : String(tokens[key]));
    });
    return out.replace(/\n{3,}/g, '\n\n').trim();
  }
  let previewTokens = {};
  try { previewTokens = JSON.parse(document.getElementById('postReplyPreviewTokensJson')?.textContent || '{}') || {}; } catch (e) { previewTokens = {}; }
  document.querySelectorAll('[data-post-reply-editor]').forEach((form) => {
    const template = form.querySelector('.post-reply-template, textarea[name="post_template"]');
    const preview = form.querySelector('.post-reply-preview');
    if (!template || !preview) return;
    const refresh = () => { preview.value = fillTemplate(template.value, previewTokens); };
    template.addEventListener('input', refresh);
  });
  document.querySelectorAll('.copy-post-preview').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const text = btn.closest('.post-reply-preview-box')?.querySelector('textarea')?.value || '';
      try { await navigator.clipboard.writeText(text); btn.textContent = '已複製'; setTimeout(() => btn.textContent = '複製預覽發文', 1200); }
      catch (e) { btn.closest('.post-reply-preview-box')?.querySelector('textarea')?.select(); document.execCommand('copy'); }
    });
  });
  document.querySelectorAll('.copy-set-qa').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const text = btn.getAttribute('data-qa-text') || '';
      try { await navigator.clipboard.writeText(text); btn.textContent = '已複製'; setTimeout(() => btn.textContent = '複製問答包', 1200); }
      catch (e) { navigator.clipboard.writeText(text); }
    });
  });
  function normalizeQuery(text) {
    return String(text || '').toLowerCase().replace(/\s+/g, '');
  }
  function matchSet(set, query) {
    const q = normalizeQuery(query);
    if (!q) return [];
    const hits = [];
    (set.qa || []).forEach((item, index) => {
      const needles = String((item.ask || '') + ',' + (item.aliases || '')).split(/[,，、\/|]+/).map((v) => v.trim()).filter(Boolean);
      let score = 0;
      needles.forEach((needle) => {
        const n = normalizeQuery(needle);
        if (!n) return;
        if (q === n) score = 100;
        else if (q.indexOf(n) !== -1 || n.indexOf(q) !== -1) score = Math.max(score, Math.min(90, 40 + n.length));
      });
      if (score > 0) hits.push({ score, ask: fillTemplate(item.ask || '', previewTokens), answer: fillTemplate(item.answer || '', previewTokens), index });
    });
    hits.sort((a, b) => b.score - a.score);
    return hits.slice(0, 5);
  }
  let sets = [];
  try { sets = JSON.parse(document.getElementById('postReplySetsJson')?.textContent || '[]') || []; } catch (e) { sets = []; }
  const setSelect = document.getElementById('postReplyMatchSet');
  const queryBox = document.getElementById('postReplyMatchQuery');
  const hitsBox = document.getElementById('postReplyMatchHits');
  function renderHits() {
    if (!hitsBox || !queryBox) return;
    const set = sets.find((row) => row.id === (setSelect?.value || '')) || sets[0];
    const hits = matchSet(set || { qa: [] }, queryBox.value);
    if (!String(queryBox.value || '').trim()) {
      hitsBox.textContent = '貼上留言後會顯示建議回答。';
      hitsBox.classList.add('muted');
      return;
    }
    hitsBox.classList.remove('muted');
    if (!hits.length) {
      hitsBox.textContent = '這句沒對到套組裡的問法。可到上面幫這套加上關鍵字。';
      return;
    }
    hitsBox.innerHTML = hits.map((hit) => {
      const ask = String(hit.ask || '').replace(/[&<>]/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[ch]));
      const answer = String(hit.answer || '').replace(/[&<>]/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[ch]));
      return '<div class="post-reply-hit"><b>Q：' + ask + '</b><pre>A：' + answer + '</pre><button type="button" class="secondary small copy-hit-answer">複製這則回答</button></div>';
    }).join('');
    hitsBox.querySelectorAll('.copy-hit-answer').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const text = (btn.previousElementSibling?.textContent || '').replace(/^A：/, '');
        try { await navigator.clipboard.writeText(text); btn.textContent = '已複製'; setTimeout(() => btn.textContent = '複製這則回答', 1200); }
        catch (e) {}
      });
    });
  }
  setSelect?.addEventListener('change', renderHits);
  queryBox?.addEventListener('input', renderHits);
})();
document.querySelectorAll('.copy-reconcile-message').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const text = btn.closest('.buyer-reconcile-card')?.querySelector('textarea')?.value || '';
    try { await navigator.clipboard.writeText(text); btn.textContent = '已複製'; setTimeout(() => btn.textContent = '複製對帳文字', 1200); }
    catch (e) { const area = btn.closest('.buyer-reconcile-card')?.querySelector('textarea'); area?.select(); document.execCommand('copy'); }
  });
});
</script>

<script id="one-dollar-nav-rescue-20260703-1655">
(function(){
  if (window.__baohuiOpsNavBound20260818 && window.openOpsTab) return;
})();
</script>


<script>
function syncOpsStaffFromMainEmployee() {
  const select = document.getElementById('opsStaffAccountSelect');
  const nameInput = document.getElementById('opsStaffNameInput');
  if (!select || !nameInput) return;
  const selected = select.options[select.selectedIndex];
  nameInput.value = selected ? (selected.dataset.name || selected.value || '') : '';
}
document.getElementById('opsStaffAccountSelect')?.addEventListener('change', syncOpsStaffFromMainEmployee);
syncOpsStaffFromMainEmployee();
function setOpsStaffPermissionSelection(selectAll) {
  document.querySelectorAll('#staff-permissions input[name="allowed_tabs[]"]').forEach(function(input) {
    input.checked = selectAll || input.value === 'overview';
  });
}
function setOpsStaffPermissionRole(role) {
  const presets = {
    shelf: ['overview','products','product-categories','shopee-workspace','color-modules','stock-search','schedule','facebook-daily','post-scripts','settlement'],
    ship: ['overview','members','member-create','customer-shipping','orders','settlement','settlement-edit','logistics','document-center'],
    warehouse: ['overview','stock-search','stock-in','inventory-count','inventory-transfer','finance-loss','finance-overage','warehouses','document-center'],
    finance: ['overview','document-center','finance-reconcile','finance-collection','finance-request','finance-bad-debt','finance-analytics','finance-report','finance-expense-categories','finance-fixed-expense','finance-fixed-asset','finance-mobile-asset']
  };
  const allowed = new Set(presets[role] || ['overview']);
  document.querySelectorAll('#staff-permissions input[name="allowed_tabs[]"]').forEach(function(input) {
    input.checked = allowed.has(input.value);
  });
}
</script>
<script id="ops-permission-guard-20260708">
(function(){
  const restricted = <?php echo $opsPermissionRestricted ? 'true' : 'false'; ?>;
  const allowed = new Set(<?php echo json_encode(array_values($opsAllowedTabs), JSON_UNESCAPED_UNICODE); ?>);
  if (!restricted) return;
  allowed.add('overview');
  function applyOpsPermissions(){
    document.querySelectorAll('.ops-tab').forEach(function(tab){
      if (!allowed.has(tab.id)) tab.classList.add('ops-no-access');
    });
    document.querySelectorAll('[data-tab-link], [data-jump-tab]').forEach(function(link){
      const tab = link.getAttribute('data-tab-link') || link.getAttribute('data-jump-tab');
      if (tab && !allowed.has(tab)) link.remove();
    });
    const current = (location.hash || '#overview').slice(1);
    if (!allowed.has(current)) {
      history.replaceState(null, '', '#overview');
      if (window.openOpsTab) window.openOpsTab('overview');
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applyOpsPermissions);
  else applyOpsPermissions();
})();
</script>


<script>
/* BAOHUI_MOBILE_DROPDOWN_NAV_CLICKFIX_20260711_START */
(function() {
  function getShell() { return document.querySelector('.ops-shell'); }
  function getButton() { return document.querySelector('.ops-mobile-nav-toggle'); }
  function getNav() { return document.getElementById('opsMobileNav') || document.querySelector('.ops-nav'); }
  function setOpen(open) {
    var shell = getShell();
    var btn = getButton();
    if (!shell || !btn) return false;
    shell.classList.toggle('ops-mobile-menu-open', !!open);
    document.body.classList.toggle('ops-mobile-menu-open', !!open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    return false;
  }
  function updateCurrentLabel() {
    var label = document.querySelector('[data-ops-mobile-current]');
    if (!label) return;
    var active = document.querySelector('#opsMobileNav [data-tab-link].active') || document.querySelector('.ops-nav [data-tab-link].active');
    if (active) label.textContent = (active.textContent || '').trim() || '功能';
  }
  window.toggleOpsMobileMenu = function(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    var shell = getShell();
    if (!shell || !getButton() || !getNav()) return false;
    var nextOpen = !shell.classList.contains('ops-mobile-menu-open');
    updateCurrentLabel();
    return setOpen(nextOpen);
  };
  document.addEventListener('click', function(event) {
    var nav = getNav();
    var btn = getButton();
    if (!nav || !btn) return;
    var target = event.target;
    var link = target.closest ? target.closest('#opsMobileNav [data-tab-link], .ops-nav [data-tab-link]') : null;
    if (link) {
      setTimeout(function() {
        updateCurrentLabel();
        setOpen(false);
      }, 120);
      return;
    }
    if (document.body.classList.contains('ops-mobile-menu-open') && !nav.contains(target) && !btn.contains(target)) {
      setOpen(false);
    }
  }, true);
  window.addEventListener('hashchange', function() {
    setTimeout(function() {
      updateCurrentLabel();
      setOpen(false);
    }, 120);
  });
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateCurrentLabel);
  } else {
    updateCurrentLabel();
  }
})();
/* BAOHUI_MOBILE_DROPDOWN_NAV_CLICKFIX_20260711_END */
</script>

<script>
(function () {
  function cropRect(start, end, width, height) {
    const x = Math.max(0, Math.min(start.x, end.x));
    const y = Math.max(0, Math.min(start.y, end.y));
    return { x: Math.min(x, width), y: Math.min(y, height), w: Math.min(Math.abs(end.x - start.x), width - x), h: Math.min(Math.abs(end.y - start.y), height - y) };
  }
  function pointAt(event, canvas) {
    const rect = canvas.getBoundingClientRect();
    return { x: (event.clientX - rect.left) * canvas.width / rect.width, y: (event.clientY - rect.top) * canvas.height / rect.height };
  }
  function drawCrop(state) {
    const { canvas, frame, selection } = state;
    const context = canvas.getContext('2d');
    context.clearRect(0, 0, canvas.width, canvas.height);
    context.drawImage(frame, 0, 0);
    if (!selection || selection.w < 2 || selection.h < 2) return;
    context.fillStyle = 'rgba(0,0,0,.45)';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(frame, selection.x, selection.y, selection.w, selection.h, selection.x, selection.y, selection.w, selection.h);
    context.strokeStyle = '#22c55e'; context.lineWidth = Math.max(3, canvas.width / 420);
    context.strokeRect(selection.x, selection.y, selection.w, selection.h);
  }
  function closeCrop(state) { state.modal.remove(); }
  async function uploadCrop(state) {
    const crop = state.selection && state.selection.w > 4 && state.selection.h > 4 ? state.selection : { x: 0, y: 0, w: state.canvas.width, h: state.canvas.height };
    const output = document.createElement('canvas');
    const max = 2200, scale = Math.min(1, max / Math.max(crop.w, crop.h));
    output.width = Math.max(1, Math.round(crop.w * scale)); output.height = Math.max(1, Math.round(crop.h * scale));
    output.getContext('2d').drawImage(state.frame, crop.x, crop.y, crop.w, crop.h, 0, 0, output.width, output.height);
    const blob = await new Promise(resolve => output.toBlob(resolve, 'image/jpeg', .9));
    if (!blob) throw new Error('無法建立截圖');
    const form = new FormData(); form.append('action', 'quick_product_images'); form.append('product_id', state.productId);
    form.append('quick_main_image', new File([blob], state.productId + '-capture.jpg', { type: 'image/jpeg' }));
    state.save.disabled = true; state.save.textContent = '上傳中...';
    const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: form, credentials: 'same-origin' });
    if (!response.ok) throw new Error('上傳失敗');
    window.location.reload();
  }
  window.captureProductMainImage = async function (button) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) { alert('此功能需要以 HTTPS 開啟，才能使用螢幕擷取。'); return; }
    const productId = button.dataset.productId || ''; if (!productId) return;
    button.disabled = true;
    try {
      const stream = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: 1 }, audio: false });
      const video = document.createElement('video'); video.srcObject = stream; video.muted = true;
      await new Promise(resolve => { if (video.readyState >= 1) resolve(); else video.onloadedmetadata = resolve; });
      await video.play();
      const frame = document.createElement('canvas'); frame.width = video.videoWidth; frame.height = video.videoHeight;
      frame.getContext('2d').drawImage(video, 0, 0); stream.getTracks().forEach(track => track.stop());
      const modal = document.createElement('div'); modal.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.8);padding:18px;display:grid;place-items:center';
      modal.innerHTML = '<div style="max-width:min(1200px,96vw);max-height:94vh;background:#fff;border-radius:8px;padding:16px;box-shadow:0 20px 50px #0008"><b style="display:block;margin:0 0 10px">拖曳框選主圖範圍</b><canvas style="display:block;max-width:92vw;max-height:70vh;cursor:crosshair;background:#111"></canvas><div style="display:flex;justify-content:flex-end;gap:10px;margin-top:12px"><button type="button" data-cancel>取消</button><button type="button" data-save style="background:#155eef;color:#fff;border:0;border-radius:5px;padding:8px 13px">設為主圖</button></div></div>';
      document.body.appendChild(modal); const canvas = modal.querySelector('canvas'); canvas.width = frame.width; canvas.height = frame.height;
      const state = { modal, canvas, frame, productId, selection: null, save: modal.querySelector('[data-save]'), start: null };
      drawCrop(state); modal.querySelector('[data-cancel]').onclick = () => closeCrop(state);
      canvas.addEventListener('pointerdown', event => { state.start = pointAt(event, canvas); canvas.setPointerCapture(event.pointerId); });
      canvas.addEventListener('pointermove', event => { if (!state.start) return; state.selection = cropRect(state.start, pointAt(event, canvas), canvas.width, canvas.height); drawCrop(state); });
      canvas.addEventListener('pointerup', () => { state.start = null; });
      state.save.onclick = async () => { try { await uploadCrop(state); } catch (error) { state.save.disabled = false; state.save.textContent = '設為主圖'; alert(error.message || '上傳失敗'); } };
    } catch (error) { if (error && error.name !== 'NotAllowedError') alert('擷取失敗，請重新操作。'); }
    finally { button.disabled = false; }
  };
})();
</script>
<script>
(function(){
  if (window.parent === window) return;
  window.parent.postMessage({ type: 'baohui-embed-height', height: 'viewport' }, '*');
})();
</script>
<script src="../baohui-paste-image.js?v=20260819-paste-1"></script>
</body>
</html>

<!-- one-dollar-nav-hard-fix-version: 20260703-0648 -->
