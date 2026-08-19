<?php
declare(strict_types=1);

/**
 * Windows-safe JSON replace. PHP rename() cannot overwrite a locked
 * destination on NTFS (Access is denied / code 5).
 */
function lz_atomic_write_json(string $file, $payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) return 'JSON 編碼失敗';

    $dir = dirname($file);
    if ($dir !== '' && !is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return '無法建立資料夾';
    }

    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return '暫存寫入失敗';
    }

    $lock = @fopen($file . '.writelock', 'c+');
    if (is_resource($lock)) {
        $got = false;
        for ($i = 0; $i < 20; $i++) {
            if (@flock($lock, LOCK_EX | LOCK_NB)) {
                $got = true;
                break;
            }
            usleep(70000);
        }
        if (!$got) @flock($lock, LOCK_EX);
    }

    $ok = false;
    for ($attempt = 1; $attempt <= 12; $attempt++) {
        if (@rename($tmp, $file)) {
            $ok = true;
            break;
        }
        if (is_file($file) && @copy($tmp, $file)) {
            @unlink($tmp);
            $ok = true;
            break;
        }
        usleep(90000 * $attempt);
    }

    if (is_resource($lock)) {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }

    if (!$ok) {
        @unlink($tmp);
        return '訂單檔正在被其他畫面占用，請再按一次儲存';
    }
    return '';
}
