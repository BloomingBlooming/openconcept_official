<?php

declare(strict_types=1);

try {
    require_once dirname(__DIR__) . '/app/bootstrap.php';
} catch (DatabaseAdapterException $exception) {
    if ($exception->failureCode() !== 'maintenance') {
        throw $exception;
    }
    $maintenanceMode = new DatabaseMaintenanceMode(dirname(__DIR__) . '/storage');
    $maintenanceUserId = (int) ($_SESSION['user_id'] ?? 0);
    $maintenanceSessionHash = hash('sha256', session_id());
    $maintenanceStatus = $maintenanceMode->operationStatus(
        $maintenanceSessionHash,
        $maintenanceUserId
    );
    $maintenanceCsrf = (string) ($_SESSION['csrf'] ?? '');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: 15');
    require_once dirname(__DIR__) . '/app/MaintenancePageRenderer.php';
    echo (new MaintenancePageRenderer())->html($maintenanceStatus, $maintenanceCsrf);
    exit;
}

$currentUser = currentUser($pdo);
$uiLocale = uiLocale($currentUser);
if (($currentUser !== null || requestedUiLocale() !== null) && ($_COOKIE['openconcept_ui_locale'] ?? null) !== $uiLocale) {
    rememberUiLocale($uiLocale);
}
?><!doctype html>
<html lang="<?= htmlspecialchars($uiLocale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f5f2">
    <script src="assets/theme.js?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/theme.js'), 0, 12) ?>"></script>
    <title>OpenConcept — <?= htmlspecialchars($i18n->translate('auth.pageTitle', [], $uiLocale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" href="app-icon.php">
    <link rel="apple-touch-icon" href="app-icon.php">
    <link rel="stylesheet" href="app-css.php">
    <link rel="stylesheet" href="rag-css.php">
    <link rel="stylesheet" href="assets/theme.css?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/theme.css'), 0, 12) ?>">
<?php foreach ($pluginManager->styleAssets() as $asset): ?>
    <link rel="stylesheet" href="plugin-asset.php?plugin=<?= rawurlencode($asset['plugin']) ?>&amp;v=<?= rawurlencode($asset['version']) ?>&amp;file=<?= rawurlencode($asset['file']) ?>">
<?php endforeach; ?>
</head>
<body>
    <div id="app" class="app-shell" data-authenticated="<?= $currentUser ? 'true' : 'false' ?>">
        <div class="app-loading"><img class="brand-mark" src="app-icon.php" alt="OpenConcept" draggable="false"><span class="loading-dot"></span></div>
    </div>
    <div id="toastRegion" class="toast-region" aria-live="polite"></div>
    <script>window.OPENCONCEPT_BOOT = <?= json_encode(['authenticated' => (bool) $currentUser, 'auth_locale' => $currentUser === null ? requestedUiLocale() : null, 'csrf' => $_SESSION['csrf'], 'app_version' => trim((string) file_get_contents(dirname(__DIR__) . '/VERSION')), 'plugins' => $pluginManager->activeVersions(), 'plugin_ui' => $pluginManager->activeSidebarItems(), 'i18n' => uiI18nBundle($currentUser)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="plugin-api-js.php" defer></script>
    <script src="app-js.php" defer></script>
    <script src="rag-js.php" defer></script>
<?php foreach ($pluginManager->scriptAssets() as $asset): ?>
    <script src="plugin-asset.php?plugin=<?= rawurlencode($asset['plugin']) ?>&amp;v=<?= rawurlencode($asset['version']) ?>&amp;file=<?= rawurlencode($asset['file']) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
