<?php

declare(strict_types=1);

$path = __DIR__ . '/assets/plugin-api.js';
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: application/javascript; charset=UTF-8');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($path);
