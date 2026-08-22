<?php
declare(strict_types=1);

function ops_data_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;
    $configured = trim((string)getenv('BAOHUI_OPS_DATA_DIR'));
    if ($configured !== '') {
        $dir = rtrim($configured, "/\\");
    } else {
        $dir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
    }
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function data_path($name): string
{
    return ops_data_dir() . DIRECTORY_SEPARATOR . $name . '.json';
}

function json_flags($flags = 0): int
{
    return $flags | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
}

function &ops_data_cache(): array
{
    static $cache = [];
    return $cache;
}

function ops_data_forget($name = null): void
{
    $cache = &ops_data_cache();
    if ($name === null) {
        $cache = [];
        return;
    }
    unset($cache['list:' . $name], $cache['object:' . $name]);
}

function ops_decode_json_file(string $file)
{
    if (!is_file($file)) return null;
    $raw = file_get_contents($file);
    if ($raw === false || $raw === '') return null;
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    $data = json_decode($raw, true, 512, json_flags());
    return is_array($data) ? $data : null;
}

function read_data($name)
{
    $cache = &ops_data_cache();
    $key = 'list:' . $name;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $data = ops_decode_json_file(data_path($name));
    $cache[$key] = is_array($data) ? $data : [];
    return $cache[$key];
}

function read_json_object($name)
{
    $cache = &ops_data_cache();
    $key = 'object:' . $name;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $data = ops_decode_json_file(data_path($name));
    $cache[$key] = is_array($data) ? $data : [];
    return $cache[$key];
}

function write_data($name, $data, array $opts = [])
{
    $rows = array_values(is_array($data) ? $data : []);
    if (($name === 'products' || $name === 'members') && empty($opts['allow_large_shrink'])) {
        $existing = ops_decode_json_file(data_path($name));
        $existingCount = is_array($existing) ? count($existing) : 0;
        $newCount = count($rows);
        if ($existingCount >= 200 && $newCount < (int)floor($existingCount * 0.85)) {
            error_log('baohui write_data refused ' . $name . ': ' . $existingCount . ' -> ' . $newCount);
            return false;
        }
    }
    $json = json_encode($rows, json_flags(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($json === false) {
        error_log('baohui write_data json_encode failed for ' . $name);
        return false;
    }
    file_put_contents(data_path($name), $json, LOCK_EX);
    $cache = &ops_data_cache();
    $cache['list:' . $name] = $rows;
    if ($name === 'products' && function_exists('ops_write_product_index_cache')) {
        ops_write_product_index_cache($rows);
    }
    return true;
}

function ops_start_html_gzip(): void
{
    if (headers_sent()) return;
    if (ini_get('zlib.output_compression')) return;
    $accept = (string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
    if (stripos($accept, 'gzip') === false) return;
    if (!function_exists('ob_gzhandler')) return;
    ob_start('ob_gzhandler');
}
