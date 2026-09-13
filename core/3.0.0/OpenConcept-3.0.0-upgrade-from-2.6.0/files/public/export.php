<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$user = requireUser($pdo);
$exportLocale = uiLocale($user);
$exportLanguage = new PageExportLocalization($i18n, $exportLocale);
$expectedPasswordFingerprint = sessionPasswordFingerprint();
if ($expectedPasswordFingerprint === null) {
    unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
    jsonResponse(['error' => localizedAuthError('ログイン状態を確認できません。もう一度ログインしてください。', $user)], 401);
}
if (!empty($user['must_change_password'])) {
    jsonResponse(['error' => localizedAuthError('続行するにはパスワードを変更してください。', $user), 'code' => 'password_change_required'], 403);
}

$pageId = max(1, (int) ($_GET['id'] ?? 0));
$format = strtolower((string) ($_GET['format'] ?? 'print'));
if (!in_array($format, ['print', 'pdf', 'md'], true)) {
    jsonResponse(['error' => $exportLanguage->text('error.format')], 422);
}

$exporter = new PageExporter($pdo, $i18n, $exportLocale);
try {
    $page = $exporter->pageForUser($pageId, $user, $expectedPasswordFingerprint);
} catch (RuntimeException $exception) {
    $status = in_array($exception->getCode(), [403, 404], true) ? $exception->getCode() : 500;
    jsonResponse(['error' => $status === 500 ? $exportLanguage->text('error.failed') : $exception->getMessage()], $status);
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
