<?php

declare(strict_types=1);

require_once __DIR__ . '/src/DrawingRepository.php';
require_once __DIR__ . '/src/DrawingOcrMetadata.php';
require_once __DIR__ . '/src/DrawingVisionClient.php';
require_once __DIR__ . '/src/DrawingMetadataExtractor.php';
require_once __DIR__ . '/src/DrawingController.php';

return static function (array $context): void {
    $pdo = $context['pdo'] ?? null;
    $appRoot = (string) ($context['app_root'] ?? '');
    if (!$pdo instanceof PDO || $appRoot === '') {
        throw new RuntimeException('図面管理プラグインにはPDO接続とアプリケーションルートが必要です。');
    }
    $i18n = $context['i18n'] ?? null;
    if ($i18n instanceof I18n) {
        $i18n->registerPackage('drawing-manager', __DIR__ . '/locales');
    }

    $verifyOnly = defined('OPENCONCEPT_PLUGIN_VERIFY_ONLY')
        && constant('OPENCONCEPT_PLUGIN_VERIFY_ONLY') === true;
    $schemaVersion = setting($pdo, 'plugin.drawing-manager.schema_version', '0');
    if ($verifyOnly && $schemaVersion !== '6') {
        throw new RuntimeException('Drawing manager schema version 6 is required for verify-only boot.');
    }

    $repository = new DrawingRepository($pdo);
    $storagePath = $appRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'drawing-manager';
    if (!$verifyOnly && $schemaVersion !== '6') {
        if (!is_dir($storagePath) && !mkdir($storagePath, 0770, true) && !is_dir($storagePath)) {
            throw new RuntimeException('図面管理の移行ロック保存先を作成できません。');
        }
        $lockHandle = fopen($storagePath . DIRECTORY_SEPARATOR . '.migration.lock', 'c');
        if ($lockHandle === false) {
            throw new RuntimeException('図面管理の移行ロックを作成できません。');
        }
        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('図面管理の移行ロックを取得できません。');
            }
            // Another request may have completed migration while this request waited for the lock.
            if (setting($pdo, 'plugin.drawing-manager.schema_version', '0') !== '6') {
                $repository->migrate();
                setSetting($pdo, 'plugin.drawing-manager.schema_version', '6');
            }
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    // v0.2 stored analyzed-but-unregistered uploads here for two hours. The old
    // register route no longer exists, so preserve that lifetime and remove only
    // expired, strictly named legacy artifacts instead of leaving drawings behind.
    $legacyPendingPath = $storagePath . DIRECTORY_SEPARATOR . 'pending';
    if (!$verifyOnly && is_dir($legacyPendingPath)) {
        $now = time();
        $cutoff = $now - 7200;
        foreach (glob($legacyPendingPath . DIRECTORY_SEPARATOR . '*.json') ?: [] as $recordPath) {
            $token = pathinfo($recordPath, PATHINFO_FILENAME);
            if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
                continue;
            }
            $record = json_decode((string) @file_get_contents($recordPath), true);
            if (is_array($record) && (int) ($record['expires_at'] ?? 0) >= $now) {
                continue;
            }
            $pendingName = is_array($record) ? basename((string) ($record['pending_name'] ?? '')) : '';
            if (preg_match('/^' . preg_quote($token, '/') . '\.(?:pdf|png|jpg|jpeg|webp|dxf|dwg|step|stp|iges|igs)$/', $pendingName) === 1) {
                @unlink($legacyPendingPath . DIRECTORY_SEPARATOR . $pendingName);
            }
            @unlink($recordPath);
        }
        foreach (glob($legacyPendingPath . DIRECTORY_SEPARATOR . '*') ?: [] as $artifactPath) {
            if (!is_file($artifactPath) || str_ends_with(strtolower($artifactPath), '.json')) {
                continue;
            }
            $name = basename($artifactPath);
            $modifiedAt = filemtime($artifactPath);
            if (preg_match('/^([a-f0-9]{48})\.(?:pdf|png|jpg|jpeg|webp|dxf|dwg|step|stp|iges|igs)$/', $name, $match) !== 1
                || !is_int($modifiedAt)
                || $modifiedAt >= $cutoff
                || is_file($legacyPendingPath . DIRECTORY_SEPARATOR . $match[1] . '.json')) {
                continue;
            }
            @unlink($artifactPath);
        }
    }

    $settingsStore = $context['ai_provider_settings'] ?? null;
    $configuration = $settingsStore instanceof AiProviderSettings ? $settingsStore->resolved() : null;
    $controller = new DrawingController(
        $repository,
        new DrawingMetadataExtractor(new DrawingVisionClient(null, $configuration)),
        $storagePath
    );
    Hooks::addAction(
        'api_request',
        static function (string $action, string $method, PDO $requestPdo, array $user) use ($controller): void {
            $controller->handle(
                $action,
                $method,
                $user,
                sessionPasswordFingerprint() ?? ''
            );
        }
    );
};
