<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = requireUser($pdo);
$expectedPasswordFingerprint = sessionPasswordFingerprint();
if ($expectedPasswordFingerprint === null) {
    unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
    jsonResponse(['error' => 'ログイン状態を確認できません。もう一度ログインしてください。'], 401);
}
if (!empty($user['must_change_password'])) {
    jsonResponse(['error' => '続行するにはパスワードを変更してください。', 'code' => 'password_change_required'], 403);
}

$pageId = max(1, (int) ($_GET['id'] ?? 0));
$format = strtolower((string) ($_GET['format'] ?? 'print'));
if (!in_array($format, ['print', 'pdf', 'md'], true)) {
    jsonResponse(['error' => '出力形式が正しくありません。'], 422);
}

$exporter = new PageExporter($pdo);
try {
    $page = $exporter->pageForUser($pageId, $user, $expectedPasswordFingerprint);
} catch (RuntimeException $exception) {
    $status = in_array($exception->getCode(), [403, 404], true) ? $exception->getCode() : 500;
    jsonResponse(['error' => $exception->getMessage()], $status);
}

session_write_close();
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');

if ($format === 'md') {
    $filename = $exporter->filename($page, 'md');
    $asciiName = 'openconcept-page-' . (int) $page['id'] . '.md';
    header('Content-Type: text/markdown; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$asciiName}\"; filename*=UTF-8''" . rawurlencode($filename));
    echo "\xEF\xBB\xBF" . $exporter->markdown($page, baseUrl());
    exit;
}

$nonce = bin2hex(random_bytes(18));
header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
echo $exporter->html($page, $format, baseUrl(), $nonce, ($_GET['preview'] ?? '') !== '1');
