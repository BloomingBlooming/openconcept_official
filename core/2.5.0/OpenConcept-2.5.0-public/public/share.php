<?php

declare(strict_types=1);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Method Not Allowed';
    exit;
}

// Tokenized, database-backed public pages were replaced by signed static
// publications with stable /published/{slug}/ URLs. Keep this endpoint inert
// so a historical token can never become a public data-reading capability.
$html = <<<'HTML'
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>公開URLは廃止されました</title>
</head>
<body><main><h1>この公開URLは廃止されました</h1><p>発行元から案内された固定URLを使用してください。</p></main></body>
</html>
HTML;

http_response_code(410);
header('Content-Type: text/html; charset=utf-8');
header('Content-Length: ' . strlen($html));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; style-src 'none'; img-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
if ($method === 'GET') {
    echo $html;
}
