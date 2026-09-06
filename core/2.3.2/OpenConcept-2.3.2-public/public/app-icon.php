<?php

declare(strict_types=1);

$iconPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'OpenConcept.png';
if (!is_file($iconPath) || !is_readable($iconPath)) {
    http_response_code(404);
    exit;
}

$modifiedAt = (int) filemtime($iconPath);
$etag = '"' . sha1((string) $modifiedAt . ':' . (string) filesize($iconPath)) . '"';
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: image/png');
header('Content-Length: ' . (string) filesize($iconPath));
header('Cache-Control: public, max-age=3600');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modifiedAt) . ' GMT');
header('X-Content-Type-Options: nosniff');
readfile($iconPath);
