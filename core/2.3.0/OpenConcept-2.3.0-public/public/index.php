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
    ?><!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OpenConcept — データベース移行中</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; background: #f6f5f2; color: #24332f; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; }
        main { width: min(620px, calc(100% - 32px)); padding: 28px; border: 1px solid #d9d5ca; border-radius: 14px; background: #fff; box-shadow: 0 18px 48px rgba(31, 50, 44, .12); }
        h1 { margin-top: 0; font-size: 1.45rem; }
        .status { margin: 20px 0; padding: 16px; border-radius: 10px; background: #fff8e6; border: 1px solid #e1bd69; }
        button { border: 0; border-radius: 8px; padding: 10px 16px; font: inherit; font-weight: 700; cursor: pointer; background: #9b2c2c; color: #fff; }
        button:disabled { cursor: not-allowed; opacity: .55; }
        small { display: block; margin-top: 16px; color: #63706c; }
    </style>
</head>
<body>
<main>
    <h1>データベース移行処理中です</h1>
    <p>正本データを保護するため、通常操作を一時停止しています。</p>
    <div class="status" role="status"><strong id="migrationStage">状態を確認しています…</strong><div id="migrationElapsed"></div></div>
    <button id="cancelMigration" type="button" hidden>中断してSQLiteを継続</button>
    <small id="migrationMessage">この画面は自動的に更新されます。</small>
</main>
<script>
(() => {
    const initial = <?= json_encode($maintenanceStatus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const csrf = <?= json_encode($maintenanceCsrf, JSON_UNESCAPED_SLASHES) ?>;
    const labels = {
        waiting_for_database: '他のデータベース処理が終わるのを待っています',
        preparing: '移行の準備中です', checking_source: 'SQLite正本を検証しています',
        connecting_destination: 'MySQLへ接続しています', checking_destination: '移行先を検証しています',
        provisioning: 'MySQLのテーブルを準備しています', copying: 'データをコピーしています',
        validating: 'コピー結果を検証しています', committing_destination: '移行先データを確定しています',
        validating_schema: '正本Schemaを最終検証しています', activating: 'MySQLを正本として有効化しています',
        unknown: '移行状態を確認しています'
    };
    const stage = document.getElementById('migrationStage');
    const elapsed = document.getElementById('migrationElapsed');
    const cancel = document.getElementById('cancelMigration');
    const message = document.getElementById('migrationMessage');
    const render = status => {
        if (!status?.active) { location.reload(); return; }
        stage.textContent = labels[status.stage] || labels.unknown;
        elapsed.textContent = `経過時間: ${Number(status.elapsed_seconds || 0)}秒`;
        cancel.hidden = !(status.owned_by_session && status.cancellable);
        cancel.disabled = Boolean(status.cancel_requested);
        if (status.cancel_requested) message.textContent = '中断要求を受け付けました。安全な処理境界で停止します。';
    };
    const status = async () => {
        const response = await fetch('api.php?action=database-migration-status', { headers: { Accept: 'application/json' } });
        const data = await response.json();
        if (response.ok) render(data.status);
    };
    cancel.onclick = async () => {
        cancel.disabled = true;
        const response = await fetch('api.php?action=database-migration-cancel', {
            method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) { message.textContent = data.error || '中断要求に失敗しました。'; cancel.disabled = false; return; }
        render(data.status); message.textContent = '中断要求を受け付けました。安全な処理境界で停止します。';
    };
    render(initial);
    setInterval(() => status().catch(() => {}), 500);
})();
</script>
</body>
</html><?php
    exit;
}

$currentUser = currentUser($pdo);
$uiLocale = uiLocale($currentUser);
?><!doctype html>
<html lang="<?= htmlspecialchars($uiLocale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f5f2">
    <title>OpenConcept — チームの知識を、ひとつの場所に</title>
    <link rel="icon" type="image/png" href="app-icon.php">
    <link rel="apple-touch-icon" href="app-icon.php">
    <link rel="stylesheet" href="app-css.php">
    <link rel="stylesheet" href="rag-css.php">
<?php foreach ($pluginManager->styleAssets() as $asset): ?>
    <link rel="stylesheet" href="plugin-asset.php?plugin=<?= rawurlencode($asset['plugin']) ?>&amp;v=<?= rawurlencode($asset['version']) ?>&amp;file=<?= rawurlencode($asset['file']) ?>">
<?php endforeach; ?>
</head>
<body>
    <div id="app" class="app-shell" data-authenticated="<?= $currentUser ? 'true' : 'false' ?>">
        <div class="app-loading"><img class="brand-mark" src="app-icon.php" alt="OpenConcept" draggable="false"><span class="loading-dot"></span></div>
    </div>
    <div id="toastRegion" class="toast-region" aria-live="polite"></div>
    <script>window.OPENCONCEPT_BOOT = <?= json_encode(['authenticated' => (bool) $currentUser, 'csrf' => $_SESSION['csrf'], 'plugins' => $pluginManager->activeVersions(), 'plugin_ui' => $pluginManager->activeSidebarItems(), 'i18n' => uiI18nBundle($currentUser)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
    <script src="app-js.php" defer></script>
    <script src="rag-js.php" defer></script>
<?php foreach ($pluginManager->scriptAssets() as $asset): ?>
    <script src="plugin-asset.php?plugin=<?= rawurlencode($asset['plugin']) ?>&amp;v=<?= rawurlencode($asset['version']) ?>&amp;file=<?= rawurlencode($asset['file']) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
