<?php
declare(strict_types=1);

function baohui_json_send(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) $json = '{"ok":false}';
    $accept = (string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '');
    if (
        $json !== ''
        && function_exists('gzencode')
        && stripos($accept, 'gzip') !== false
        && !ini_get('zlib.output_compression')
        && !headers_sent()
    ) {
        $gzip = gzencode($json, 6);
        if ($gzip !== false) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            $json = $gzip;
        }
    }
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}
