<?php

declare(strict_types=1);

$path = dirname(__DIR__) . '/assets/rag-core.js';
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/javascript; charset=UTF-8');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
readfile($path);
