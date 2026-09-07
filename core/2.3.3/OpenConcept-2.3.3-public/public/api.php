<?php

declare(strict_types=1);

$requestedActionBeforeBootstrap = (string) ($_GET['action'] ?? 'bootstrap');
$databaseMigrationControlActions = [
    'database-migration-status',
    'database-migration-cancel',
];
if (in_array($requestedActionBeforeBootstrap, $databaseMigrationControlActions, true)) {
    require_once dirname(__DIR__) . '/app/DatabaseAdapterException.php';
    require_once dirname(__DIR__) . '/app/DatabaseMaintenanceMode.php';
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('openconcept_session');
        session_set_cookie_params([
            'httponly' => true,
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }
    $controlUserId = (int) ($_SESSION['user_id'] ?? 0);
    $controlPasswordFingerprint = (string) ($_SESSION['user_password_fingerprint'] ?? '');
    $controlCsrf = (string) ($_SESSION['csrf'] ?? '');
    $controlSessionHash = hash('sha256', session_id());
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($controlUserId < 1
        || preg_match('/^[a-f0-9]{64}$/D', $controlPasswordFingerprint) !== 1) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication is required.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $maintenanceMode = new DatabaseMaintenanceMode(dirname(__DIR__) . '/storage');
    try {
        if ($requestedActionBeforeBootstrap === 'database-migration-cancel') {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                throw new DatabaseAdapterException('POST is required.', 'invalid_request');
            }
            $providedCsrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if ($controlCsrf === '' || !hash_equals($controlCsrf, $providedCsrf)) {
                throw new DatabaseAdapterException('The security token is invalid.', 'invalid_csrf');
            }
            $status = $maintenanceMode->requestCancellation($controlSessionHash, $controlUserId);
            echo json_encode(['ok' => true, 'status' => $status], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            throw new DatabaseAdapterException('GET is required.', 'invalid_request');
        }
        echo json_encode([
            'status' => $maintenanceMode->operationStatus($controlSessionHash, $controlUserId),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    } catch (DatabaseAdapterException $exception) {
        $status = match ($exception->failureCode()) {
            'invalid_csrf', 'migration_cancel_forbidden' => 403,
            'migration_not_active' => 409,
            'invalid_request' => 405,
            default => 500,
        };
        http_response_code($status);
        echo json_encode([
            'error' => $exception->getMessage(),
            'code' => $exception->failureCode(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}
$databaseMigrationSetupActions = [
    'plugin-database-mysql-adapter-setup',
    'plugin-database-mysql-adapter-relocate',
    'plugin-database-postgresql-adapter-setup',
];
$databaseMaintenanceLease = null;
if (!in_array($requestedActionBeforeBootstrap, $databaseMigrationSetupActions, true)) {
    require_once dirname(__DIR__) . '/app/DatabaseAdapterException.php';
    require_once dirname(__DIR__) . '/app/DatabaseMaintenanceMode.php';
    $maintenanceMode = new DatabaseMaintenanceMode(dirname(__DIR__) . '/storage');
    try {
        $databaseMaintenanceLease = $maintenanceMode->acquireShared();
        register_shutdown_function(static function () use (&$databaseMaintenanceLease): void {
            if (is_resource($databaseMaintenanceLease)) {
                flock($databaseMaintenanceLease, LOCK_UN);
                fclose($databaseMaintenanceLease);
            }
        });
    } catch (DatabaseAdapterException) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Retry-After: 30');
        echo json_encode([
            'error' => 'Database migration maintenance is active. Please retry shortly.',
            'maintenance' => true,
            'migration' => $maintenanceMode->operationStatus(),
            'status_action' => 'database-migration-status',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}

$deploymentWriteGatePath = trim((string) getenv('OPENCONCEPT_DEPLOYMENT_WRITE_GATE_PATH'));
if ($deploymentWriteGatePath === '') {
    $deploymentWriteGatePath = dirname(__DIR__) . '/storage/.deployment-write-gate';
}
if (is_file($deploymentWriteGatePath)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Retry-After: 60');
    echo json_encode([
        'error' => '安全な更新処理のため、一時的にメンテナンス中です。しばらくしてから再試行してください。',
        'maintenance' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

const OPENCONCEPT_WRITER_GENERATION = 'normalized-department-acl-v1';

require_once dirname(__DIR__) . '/app/bootstrap.php';

$action = (string) ($_GET['action'] ?? 'bootstrap');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (isset($_SERVER['HTTP_X_OPENCONCEPT_BODY_ENCODING'])
    && in_array($action, ['session', 'initialize', 'login'], true)) {
    jsonResponse(['error' => 'この操作では符号化した送信データを受け付けません。', 'code' => 'encoded_body_not_allowed'], 415);
}

if ($action === 'session') {
    $user = currentUser($pdo);
    jsonResponse([
        'initialized' => isInitialized($pdo), 'user' => $user, 'csrf' => $_SESSION['csrf'],
        'settings' => wafCompatibilitySettings($pdo, $user !== null),
    ]);
}

if ($action === 'initialize' && $method === 'POST') {
    requireCsrf();
    if (isInitialized($pdo)) {
        jsonResponse(['error' => '初期セットアップは完了しています。'], 409);
    }

    $activeUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE active = 1 AND role NOT IN ('system', 'suspended')")->fetchColumn();
    if ($activeUsers > 0) {
        jsonResponse(['error' => '有効なユーザーが存在するため初期化できません。'], 409);
    }

    $body = bodyJson();
    $name = cleanText((string) ($body['name'] ?? ''), 120);
    $email = lowerText(cleanText((string) ($body['email'] ?? ''), 190));
    $password = (string) ($body['password'] ?? '');
    $confirmation = (string) ($body['password_confirmation'] ?? '');
    if ($name === '') {
        jsonResponse(['error' => '管理者名を入力してください。'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => '正しいメールアドレスを入力してください。'], 422);
    }
    if ($password !== $confirmation) {
        jsonResponse(['error' => '確認用パスワードが一致しません。'], 422);
    }
    if ($error = passwordError($password)) {
        jsonResponse(['error' => $error], 422);
    }

    $pdo->beginTransaction();
    try {
        // The primary key on this row is also the cross-request setup lock.
        // Only one concurrent first-access request can create the administrator.
        $lock = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('initialized', 'pending', CURRENT_TIMESTAMP)");
        $lock->execute();

        $returnsAdministratorId = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $administratorInsertSql = 'INSERT INTO users (name, email, password_hash, avatar_color, role, department, active, must_change_password, last_login_at) VALUES (?, ?, ?, ?, ?, ?, 1, 0, CURRENT_TIMESTAMP)';
        if ($returnsAdministratorId) {
            $administratorInsertSql .= ' RETURNING id';
        }
        $insert = $pdo->prepare($administratorInsertSql);
        $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), '#304B45', 'admin', '管理部門']);
        $adminId = $returnsAdministratorId
            ? (int) $insert->fetchColumn()
            : (int) $pdo->lastInsertId();

        $systemIds = $pdo->query("SELECT id FROM users WHERE role = 'system'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($systemIds as $systemId) {
            $pdo->prepare('UPDATE pages SET author_id = ?, updated_by = ? WHERE author_id = ? OR updated_by = ?')->execute([$adminId, $adminId, (int) $systemId, (int) $systemId]);
        }
        DefaultOperationManual::register($pdo, $adminId);
        $pdo->exec("UPDATE system_settings SET setting_value = '1', updated_at = CURRENT_TIMESTAMP WHERE setting_key = 'initialized'");
        setSetting($pdo, 'workspace_name', 'OpenConcept');
        audit($pdo, $adminId, 'initialized', 'system', 1, ['administrator' => $email]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        if ($exception instanceof PDOException && (str_contains($exception->getMessage(), 'UNIQUE') || str_contains($exception->getMessage(), 'Duplicate'))) {
            if (isInitialized($pdo) || str_contains($exception->getMessage(), 'system_settings')) {
                jsonResponse(['error' => '初期セットアップは完了しています。'], 409);
            }
            jsonResponse(['error' => 'このメールアドレスは既に使用されています。'], 422);
        }
        throw $exception;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $adminId;
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    jsonResponse(['ok' => true, 'csrf' => $_SESSION['csrf'], 'user' => currentUser($pdo)], 201);
}

if ($action === 'login' && $method === 'POST') {
    if (!isInitialized($pdo)) {
        jsonResponse(['error' => '最初に管理者セットアップを完了してください。'], 409);
    }
    $body = bodyJson();
    $attempts = (array) ($_SESSION['login_attempts'] ?? []);
    $attempts = array_values(array_filter($attempts, static fn($timestamp): bool => (int) $timestamp > time() - 60));
    $email = lowerText(cleanText((string) ($body['email'] ?? ''), 180));
    $statement = $pdo->prepare("SELECT * FROM users WHERE email = ? AND active = 1 AND role <> 'suspended'");
    $statement->execute([$email]);
    $user = $statement->fetch();
    if (!$user || !password_verify((string) ($body['password'] ?? ''), $user['password_hash'])) {
        if (count($attempts) >= 5) {
            jsonResponse(['error' => '試行回数が多すぎます。1分後にもう一度お試しください。'], 429);
        }
        $attempts[] = time();
        $_SESSION['login_attempts'] = $attempts;
        usleep(250000);
        jsonResponse(['error' => 'メールアドレスまたはパスワードが違います。'], 422);
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_password_fingerprint'] = hash('sha256', (string) $user['password_hash']);
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    unset($_SESSION['login_attempts']);
    $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $user['id']]);
    audit($pdo, (int) $user['id'], 'login', 'user', (int) $user['id']);
    jsonResponseWithDeferredWork(
        ['ok' => true, 'csrf' => $_SESSION['csrf'], 'must_change_password' => (bool) $user['must_change_password']],
        static function () use ($pdo, $deploymentWriteGatePath): void {
            (new RagLoginWorker(
                $pdo,
                new OpenAIClient(),
                dirname(__DIR__) . '/storage',
                $deploymentWriteGatePath
            ))->run();
        }
    );
}

$user = requireUser($pdo);
$expectedPasswordFingerprint = sessionPasswordFingerprint();
if ($expectedPasswordFingerprint === null) {
    unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
    jsonResponse(['error' => 'ログイン状態を確認できません。もう一度ログインしてください。'], 401);
}

$GLOBALS['openconceptEncodedJsonAllowed'] = setting($pdo, JsonRequestBody::SETTING_KEY, '0') === '1';
$GLOBALS['openconceptEncryptedJsonKey'] = $GLOBALS['openconceptEncodedJsonAllowed']
    ? setting($pdo, JsonRequestBody::KEY_SETTING) : null;
if (isset($_SERVER['HTTP_X_OPENCONCEPT_BODY_ENCODING'])) {
    if ($method !== 'POST') {
        jsonResponse(['error' => 'この操作では符号化した送信データを受け付けません。', 'code' => 'encoded_body_not_allowed'], 415);
    }
    // Validate before dispatch even for actions that do not consume a body.
    bodyJson();
}

/**
 * Publication impact lookup is bounded per query, while an ACL inheritance
 * update can legitimately touch more pages than a single public site allows.
 * Split the lookup and deduplicate by publication root so a successful write
 * never turns into a post-commit 422 response merely because its subtree is
 * large.
 *
 * @param array<int, mixed> $pageIds
 * @return array<int, array<string, mixed>>
 */
$affectedPublicationsForPageIds = static function (array $pageIds) use (
    $pdo,
    $user,
    $expectedPasswordFingerprint
): array {
    $byRoot = [];
    $sharing = new PublicPageSharing($pdo);
    foreach (array_chunk($pageIds, StaticPublicSitePublisher::MAX_PAGES) as $pageIdChunk) {
        foreach ($sharing->affectedPublicationsForPagesForUser(
            $pageIdChunk,
            $user,
            $expectedPasswordFingerprint
        ) as $publication) {
            $byRoot[(int) $publication['root_page_id']] = $publication;
        }
    }
    ksort($byRoot, SORT_NUMERIC);
    return array_values($byRoot);
};

if ($action === 'logout' && $method === 'POST') {
    requireCsrf();
    audit($pdo, (int) $user['id'], 'logout', 'user', (int) $user['id']);
    $_SESSION = [];
    session_destroy();
    jsonResponse(['ok' => true]);
}

if ($action === 'change-password' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $currentPassword = (string) ($body['current_password'] ?? '');
    $password = (string) ($body['password'] ?? '');
    $confirmation = (string) ($body['password_confirmation'] ?? '');
    if ($password !== $confirmation) {
        jsonResponse(['error' => '確認用パスワードが一致しません。'], 422);
    }
    if ($error = passwordError($password)) {
        jsonResponse(['error' => $error], 422);
    }
    $newPasswordHash = password_hash($password, PASSWORD_DEFAULT);
    try {
        beginPageWriteTransaction($pdo);
        $lockedUser = lockedActiveApplicationUserForWrite($pdo, (int) $user['id']);
        if ($lockedUser === null
            || !validPasswordFingerprint($expectedPasswordFingerprint)
            || !hash_equals(
                hash('sha256', (string) ($lockedUser['password_hash'] ?? '')),
                $expectedPasswordFingerprint
            )) {
            throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
        }
        $forcedChange = (bool) ($lockedUser['must_change_password'] ?? false);
        if (!$forcedChange) {
            requireCurrentPasswordForSensitiveAction($lockedUser, $currentPassword);
        }
        if (password_verify($password, (string) $lockedUser['password_hash'])) {
            throw new RuntimeException('現在のパスワードとは異なるパスワードを設定してください。', 422);
        }
        $update = $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?');
        $update->execute([$newPasswordHash, (int) $lockedUser['id']]);
        audit($pdo, (int) $lockedUser['id'], 'password_changed', 'user', (int) $lockedUser['id'], [
            'forced_change' => $forcedChange,
            'other_sessions_invalidated' => true,
        ]);
        $pdo->commit();
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int) $exception->getCode() === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        $status = in_array((int) $exception->getCode(), [401, 422], true)
            ? (int) $exception->getCode()
            : 500;
        jsonResponse(['error' => $exception->getMessage()], $status);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    session_regenerate_id(true);
    $_SESSION['user_password_fingerprint'] = hash('sha256', $newPasswordHash);
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    jsonResponse(['ok' => true, 'csrf' => $_SESSION['csrf']]);
}

if (!empty($user['must_change_password'])) {
    jsonResponse(['error' => '続行するにはパスワードを変更してください。', 'code' => 'password_change_required'], 403);
}

if ($action === 'waf-compatibility-settings' && $method === 'GET') {
    requireRole($user, ['admin']);
    jsonResponse(['settings' => wafCompatibilitySettings($pdo, true)]);
}

if ($action === 'waf-compatibility-settings' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    $body = bodyJson();
    if (array_keys($body) !== ['waf_compatibility_enabled'] || !is_bool($body['waf_compatibility_enabled'])) {
        jsonResponse(['error' => 'WAF誤検知を回避する設定を確認してください。', 'code' => 'invalid_waf_compatibility_setting'], 422);
    }
    try {
        beginPageWriteTransaction($pdo);
        $liveAdmin = requireLockedApplicationAdminForWrite($pdo, $user, $expectedPasswordFingerprint);
        $enabled = $body['waf_compatibility_enabled'];
        if ($enabled) {
            if (!JsonRequestBody::encryptionAvailable()) {
                throw new JsonRequestBodyException('このサーバーではAES-GCM暗号化を利用できません。管理者に設定の確認を依頼してください。', 503, 'waf_encryption_unavailable');
            }
            if (JsonRequestBody::decodeKey(setting($pdo, JsonRequestBody::KEY_SETTING)) === null) {
                setSetting($pdo, JsonRequestBody::KEY_SETTING, base64_encode(random_bytes(32)));
            }
        }
        setSetting($pdo, JsonRequestBody::SETTING_KEY, $enabled ? '1' : '0');
        audit($pdo, (int) $liveAdmin['id'], 'waf_compatibility_settings_updated', 'system', 1, [
            'waf_compatibility_enabled' => $enabled,
        ]);
        $settings = wafCompatibilitySettings($pdo, true);
        $pdo->commit();
        jsonResponse(['ok' => true, 'settings' => $settings]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = in_array((int) $exception->getCode(), [401, 403, 409, 503], true) ? (int) $exception->getCode() : 500;
        jsonResponse([
            'error' => $status === 500 ? 'WAF誤検知を回避する設定を保存できませんでした。' : $exception->getMessage(),
            'code' => $exception instanceof JsonRequestBodyException ? $exception->failureCode : 'waf_settings_save_failed',
        ], $status);
    }
}

if ($action === 'ai-provider-settings' && $method === 'GET') {
    requireRole($user, ['admin']);
    try {
        jsonResponse($aiProviderSettings->publicState());
    } catch (Throwable $exception) {
        jsonResponse(['error' => 'AIプロバイダー設定を読み込めませんでした。'], 500);
    }
}

if ($action === 'ai-provider-settings' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    try {
        beginPageWriteTransaction($pdo);
        $liveAdmin = requireLockedApplicationAdminForWrite($pdo, $user, $expectedPasswordFingerprint);
        $state = $aiProviderSettings->save(bodyJson());
        audit($pdo, (int) $liveAdmin['id'], 'ai_provider_settings_updated', 'system', 1, [
            'provider_id' => (string) ($state['settings']['provider_id'] ?? ''),
            'endpoint_mode' => (string) ($state['settings']['endpoint_mode'] ?? ''),
            'auth_mode' => (string) ($state['settings']['auth_mode'] ?? ''),
        ]);
        $pdo->commit();
        jsonResponse(['ok' => true] + $state);
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => $exception->getMessage()], 422);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = in_array((int) $exception->getCode(), [401, 403], true) ? (int) $exception->getCode() : 500;
        jsonResponse(['error' => $status === 500 ? 'AIプロバイダー設定を保存できませんでした。' : $exception->getMessage()], $status);
    }
}

if ($action === 'ai-provider-test' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    try {
        beginPageWriteTransaction($pdo);
        $liveAdmin = requireLockedApplicationAdminForWrite($pdo, $user, $expectedPasswordFingerprint);
        $pdo->commit();
        $result = (new OpenAIClient(null, $aiProviderSettings->resolved()))->healthCheck();
        audit($pdo, (int) $liveAdmin['id'], 'ai_provider_connection_tested', 'system', 1, [
            'provider' => (string) ($result['provider'] ?? ''),
            'status' => (int) ($result['status'] ?? 0),
        ]);
        jsonResponse(['ok' => true, 'result' => $result]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = in_array((int) $exception->getCode(), [401, 403], true) ? (int) $exception->getCode() : 422;
        jsonResponse(['error' => $exception->getMessage()], $status);
    }
}

if ($action === 'translation-provider-settings' && $method === 'GET') {
    requireRole($user, ['admin']);
    try {
        $state = $translationProviderSettings->publicState();
        $plugin = $pluginManager->plugin('translation-openai');
        jsonResponse($state + [
            'default_provider_id' => (string) setting($pdo, 'translation.default_provider_id', 'openai'),
            'providers' => $translationProviders->summaries(),
            'plugin_enabled' => is_array($plugin) && (bool) ($plugin['enabled'] ?? false),
        ]);
    } catch (Throwable) {
        jsonResponse(['error' => '翻訳プロバイダー設定を読み込めませんでした。'], 500);
    }
}

if ($action === 'translation-provider-settings' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    try {
        $body = bodyJson();
        $defaultProviderId = strtolower(trim((string) ($body['default_provider_id'] ?? 'openai')));
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $defaultProviderId) !== 1) {
            throw new InvalidArgumentException('既定の翻訳プロバイダーが正しくありません。');
        }
        beginPageWriteTransaction($pdo);
        $liveAdmin = requireLockedApplicationAdminForWrite($pdo, $user, $expectedPasswordFingerprint);
        $state = $translationProviderSettings->save($body);
        setSetting($pdo, 'translation.default_provider_id', $defaultProviderId);
        audit($pdo, (int) $liveAdmin['id'], 'translation_provider_settings_updated', 'system', 1, [
            'default_provider_id' => $defaultProviderId,
            'provider_id' => (string) ($state['settings']['provider_id'] ?? ''),
            'endpoint_mode' => (string) ($state['settings']['endpoint_mode'] ?? ''),
            'auth_mode' => (string) ($state['settings']['auth_mode'] ?? ''),
        ]);
        $pdo->commit();
        $plugin = $pluginManager->plugin('translation-openai');
        $pluginEnabled = is_array($plugin) && (bool) ($plugin['enabled'] ?? false);
        $providerSummaries = $translationProviders->summaries();
        foreach ($providerSummaries as &$providerSummary) {
            if (($providerSummary['id'] ?? '') === 'openai') {
                $providerSummary['label'] = (string) ($state['settings']['display_name'] ?? $providerSummary['label']);
                $providerSummary['available'] = $pluginEnabled && (bool) ($state['secret']['available'] ?? false);
            }
        }
        unset($providerSummary);
        jsonResponse(['ok' => true] + $state + [
            'default_provider_id' => $defaultProviderId,
            'providers' => $providerSummaries,
            'plugin_enabled' => $pluginEnabled,
        ]);
    } catch (InvalidArgumentException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => $exception->getMessage()], 422);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = in_array((int) $exception->getCode(), [401, 403], true) ? (int) $exception->getCode() : 500;
        jsonResponse(['error' => $status === 500 ? '翻訳プロバイダー設定を保存できませんでした。' : $exception->getMessage()], $status);
    }
}

if ($action === 'translation-provider-test' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    try {
        beginPageWriteTransaction($pdo);
        $liveAdmin = requireLockedApplicationAdminForWrite($pdo, $user, $expectedPasswordFingerprint);
        $pdo->commit();
        $providerId = (string) setting($pdo, 'translation.default_provider_id', 'openai');
        $provider = $translationProviders->registeredProvider($providerId);
        if (!$provider instanceof TranslationProviderHealthCheckInterface) {
            throw new RuntimeException('この翻訳プロバイダーは接続テストに対応していません。', 422);
        }
        $result = $provider->healthCheck();
        audit($pdo, (int) $liveAdmin['id'], 'translation_provider_connection_tested', 'system', 1, [
            'provider_id' => $providerId,
            'status' => (int) ($result['status'] ?? 0),
        ]);
        jsonResponse(['ok' => true, 'result' => $result]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = in_array((int) $exception->getCode(), [401, 403], true) ? (int) $exception->getCode() : 422;
        jsonResponse(['error' => $exception->getMessage()], $status);
    }
}

// RAG orchestration is a built-in OpenConcept API surface. Retrieval and
// answer provider plugins register capabilities, but do not own this route.
if ($ragCoreRuntime instanceof RagCoreRuntime) {
    try {
        $ragCoreRuntime->controller()->handle($action, $method, $user);
    } catch (DeploymentRequirementException $exception) {
        $details = $exception->details();
        jsonResponse([
            'error' => $exception->getMessage(),
            'code' => $exception->failureCode(),
            'details' => $details,
            'settings_target' => (string) ($details['settings_target'] ?? 'database'),
        ], 422);
    } catch (RagProviderUnavailableException $exception) {
        $details = $exception->details();
        jsonResponse([
            'error' => $exception->getMessage(),
            'code' => $exception->failureCode(),
            'details' => $details,
            'settings_target' => (string) ($details['settings_target'] ?? 'retrieval'),
        ], 422);
    } catch (InvalidArgumentException $exception) {
        jsonResponse([
            'error' => $exception->getMessage(),
            'code' => 'invalid_configuration',
            'settings_target' => 'save',
        ], 422);
    } catch (Throwable $exception) {
        $failureCode = 'rag_runtime_error';
        $settingsTarget = 'save';
        $status = 500;
        $publicMessage = 'The RAG operation failed.';
        if (preg_match('/unavailable:\s*([a-z][a-z0-9_]*)\s*$/D', $exception->getMessage(), $match) === 1) {
            $failureCode = (string) $match[1];
            $settingsTarget = in_array($failureCode, ['postgresql_required', 'pgvector_missing'], true)
                ? 'database'
                : 'retrieval';
            $status = 422;
            $publicMessage = $failureCode === 'embedding_unavailable'
                ? 'The Standard RAG embedding service is unavailable.'
                : 'The selected RAG provider is unavailable: ' . $failureCode . '.';
        }
        error_log('RAG Core API failed [' . $failureCode . ']: ' . $exception->getMessage());
        jsonResponse([
            'error' => $publicMessage,
            'code' => $failureCode,
            'settings_target' => $settingsTarget,
        ], $status);
    }
}

// Enabled plugins can own namespaced API actions without adding their routes
// to the application core. A plugin handler must send a response and exit.
Hooks::action('api_request', $action, $method, $pdo, $user);

if ($action === 'update-ui-locale' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $requestedLocale = trim((string) ($body['locale'] ?? ''));
    if (!I18n::isValidLocale($requestedLocale) || $i18n->selectLocale($requestedLocale) !== $requestedLocale) {
        jsonResponse(['error' => '利用可能な画面言語を選択してください。'], 422);
    }
    beginPageWriteTransaction($pdo);
    try {
        $liveUser = lockedAuthenticatedApplicationUserForWrite($pdo, $user, $expectedPasswordFingerprint);
        if ($liveUser === null) {
            throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
        }
        $pdo->prepare('UPDATE users SET ui_locale = ? WHERE id = ?')->execute([$requestedLocale, (int) $liveUser['id']]);
        audit($pdo, (int) $liveUser['id'], 'ui_locale_updated', 'user', (int) $liveUser['id'], ['locale' => $requestedLocale]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = in_array((int) $exception->getCode(), [401, 403, 422], true) ? (int) $exception->getCode() : 500;
        jsonResponse(['error' => $status === 500 ? '画面言語を更新できませんでした。' : $exception->getMessage()], $status);
    }
    $user['ui_locale'] = $requestedLocale;
    jsonResponse(['ok' => true, 'i18n' => $i18n->bundle($requestedLocale)]);
}

if ($action === 'profile-avatar' && $method === 'GET') {
    $memberId = max(1, (int) ($_GET['id'] ?? 0));
    $statement = $pdo->prepare("SELECT avatar_value FROM users WHERE id = ? AND active = 1 AND role <> 'suspended' AND avatar_kind = 'photo'");
    $statement->execute([$memberId]);
    $storedName = (string) ($statement->fetchColumn() ?: '');
    if (!validStoredProfilePhoto($memberId, $storedName)) {
        jsonResponse(['error' => 'プロフィール写真が見つかりません。'], 404);
    }
    $path = uploadStoragePath() . DIRECTORY_SEPARATOR . $storedName;
    if (!is_file($path)) {
        jsonResponse(['error' => 'プロフィール写真が見つかりません。'], 404);
    }
    $extension = lowerText((string) pathinfo($storedName, PATHINFO_EXTENSION));
    $mime = match ($extension) {
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        default => '',
    };
    if ($mime === '') {
        jsonResponse(['error' => 'プロフィール写真の形式が正しくありません。'], 404);
    }
    session_write_close();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="profile.' . $extension . '"');
    header('Cache-Control: private, max-age=86400, immutable');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    readfile($path);
    exit;
}

if ($action === 'update-profile' && $method === 'POST') {
    requireCsrf();
    $avatarKind = cleanText((string) ($_POST['avatar_kind'] ?? 'initials'), 16);
    $avatarValue = cleanText((string) ($_POST['avatar_value'] ?? ''), 255);

    $oldPhoto = '';
    $newPhoto = null;
    $photo = $_FILES['photo'] ?? null;
    if ($avatarKind === 'photo') {
        if (is_array($photo) && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $message = in_array($photo['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? '写真のサイズがサーバーの上限を超えています。'
                    : '写真をアップロードできませんでした。';
                jsonResponse(['error' => $message], 422);
            }
            $size = (int) ($photo['size'] ?? 0);
            if ($size < 1 || $size > profilePhotoLimitBytes()) {
                $limitMb = max(1, (int) floor(profilePhotoLimitBytes() / 1024 / 1024));
                jsonResponse(['error' => 'プロフィール写真は' . $limitMb . 'MB以下にしてください。'], 422);
            }
            $temporaryPath = (string) ($photo['tmp_name'] ?? '');
            if (!is_uploaded_file($temporaryPath)) {
                jsonResponse(['error' => '正しいアップロード画像ではありません。'], 422);
            }
            $originalName = cleanText(basename(str_replace('\\', '/', (string) ($photo['name'] ?? ''))), 255);
            $inspected = inspectProfilePhoto($temporaryPath, $originalName);
            if (!$inspected) {
                jsonResponse(['error' => 'プロフィール写真はPNG、JPEG、WebPを使用してください。'], 422);
            }
            $uploadDirectory = uploadStoragePath();
            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0770, true) && !is_dir($uploadDirectory)) {
                jsonResponse(['error' => '写真の保存先を作成できません。'], 500);
            }
            $newPhoto = 'avatar-' . (int) $user['id'] . '-' . bin2hex(random_bytes(24)) . '.' . $inspected['extension'];
            $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $newPhoto;
            if (!move_uploaded_file($temporaryPath, $destination)) {
                jsonResponse(['error' => 'プロフィール写真を保存できませんでした。'], 500);
            }
            $avatarValue = $newPhoto;
        }
    } elseif (is_array($photo) && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        jsonResponse(['error' => '写真を使用する場合は、写真のプレビューを選択してください。'], 422);
    }

    try {
        beginPageWriteTransaction($pdo);
        $liveUser = lockedAuthenticatedApplicationUserForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        if ($liveUser === null) {
            throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
        }
        $oldPhoto = ($liveUser['avatar_kind'] ?? '') === 'photo'
            ? (string) ($liveUser['avatar_value'] ?? '')
            : '';
        if ($avatarKind === 'photo' && $newPhoto === null) {
            $existingPhoto = (string) ($liveUser['avatar_value'] ?? '');
            if (($liveUser['avatar_kind'] ?? '') !== 'photo'
                || !validStoredProfilePhoto((int) $liveUser['id'], $existingPhoto)) {
                throw new RuntimeException('使用するプロフィール写真を選択してください。', 422);
            }
            $avatarValue = $existingPhoto;
        }
        $department = (string) ($liveUser['department'] ?? '');
        if ((string) $liveUser['role'] === 'admin' && array_key_exists('department', $_POST)) {
            if (!is_string($_POST['department'])) {
                throw new RuntimeException('部署名を確認してください。', 422);
            }
            $department = $_POST['department'];
        }
        $updatedUser = updateUserProfile(
            $pdo,
            (int) $liveUser['id'],
            $department,
            $avatarKind,
            $avatarValue
        );
        if (!$updatedUser) {
            throw new RuntimeException('プロフィールの内容を確認してください。', 422);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($newPhoto !== null) {
            @unlink(uploadStoragePath() . DIRECTORY_SEPARATOR . $newPhoto);
        }
        if ($exception instanceof RuntimeException
            && in_array((int) $exception->getCode(), [401, 422], true)) {
            if ((int) $exception->getCode() === 401) {
                unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
            }
            jsonResponse(['error' => $exception->getMessage()], (int) $exception->getCode());
        }
        throw $exception;
    }

    if ($oldPhoto !== '' && $oldPhoto !== $updatedUser['avatar_value']) {
        deleteUnusedProfilePhoto($pdo, $oldPhoto);
    }
    jsonResponse(['ok' => true, 'user' => $updatedUser]);
}

if ($action === 'ai-conversations' && $method === 'GET') {
    $repository = new AiChatRepository($pdo);
    jsonResponse(['conversations' => $repository->listForUser(
        (int) $user['id'],
        $expectedPasswordFingerprint
    )]);
}

if ($action === 'ai-conversation' && $method === 'GET') {
    $conversationId = (int) ($_GET['id'] ?? 0);
    if ($conversationId < 1) {
        jsonResponse(['error' => 'AIチャットを選択してください。'], 422);
    }
    try {
        $repository = new AiChatRepository($pdo);
        jsonResponse($repository->getForUser(
            $conversationId,
            (int) $user['id'],
            $expectedPasswordFingerprint
        ));
    } catch (InvalidArgumentException $exception) {
        jsonResponse(['error' => $exception->getMessage()], 404);
    }
}

/** @param array<string, mixed> $pending @return array<string, mixed> */
function storePendingAiPageAction(array $pending, int $conversationId, int $turnId): array
{
    if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
        throw new RuntimeException('AI変更案の確認状態を保存できません。');
    }
    $now = time();
    $stored = is_array($_SESSION['pending_ai_page_actions'] ?? null) ? $_SESSION['pending_ai_page_actions'] : [];
    $stored = array_filter($stored, static fn(mixed $item): bool => is_array($item) && (int) ($item['created_at'] ?? 0) >= $now - 600);
    $token = bin2hex(random_bytes(24));
    $pending['conversation_id'] = $conversationId;
    $pending['turn_id'] = $turnId;
    $pending['created_at'] = $now;
    $stored[$token] = $pending;
    $_SESSION['pending_ai_page_actions'] = array_slice($stored, -12, null, true);
    session_write_close();

    return [
        'type' => 'confirm_page_action',
        'token' => $token,
        'operation' => (string) ($pending['operation'] ?? ''),
        'page_id' => (int) ($pending['page_id'] ?? 0),
        'preview' => is_array($pending['preview'] ?? null) ? $pending['preview'] : [],
    ];
}

/** @return array<string, mixed>|null */
function takePendingAiPageAction(string $token): ?array
{
    $now = time();
    $stored = is_array($_SESSION['pending_ai_page_actions'] ?? null) ? $_SESSION['pending_ai_page_actions'] : [];
    $pending = is_array($stored[$token] ?? null) ? $stored[$token] : null;
    unset($stored[$token]);
    $_SESSION['pending_ai_page_actions'] = array_filter(
        $stored,
        static fn(mixed $item): bool => is_array($item) && (int) ($item['created_at'] ?? 0) >= $now - 600
    );
    if ($pending === null || (int) ($pending['created_at'] ?? 0) < $now - 600) {
        return null;
    }
    return $pending;
}

/**
 * Keep internal application failures private while returning the already
 * redacted diagnostics attached to a shared AI provider failure.
 *
 * @return array<string, mixed>
 */
function aiFailureResponsePayload(Throwable $exception, string $fallbackMessage): array
{
    $payload = ['error' => $fallbackMessage];
    if ($exception instanceof AiProviderRequestException) {
        $payload['code'] = $exception->failureCode();
        $payload['details'] = $exception->details();
    }
    return $payload;
}

if ($action === 'ai-page-action-confirm' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $token = preg_replace('/[^a-f0-9]/', '', strtolower((string) ($body['token'] ?? ''))) ?? '';
    if (strlen($token) !== 48) {
        jsonResponse(['error' => 'AI変更案の確認情報が正しくありません。'], 422);
    }
    $pending = takePendingAiPageAction($token);
    if ($pending === null || (int) ($pending['user_id'] ?? 0) !== (int) $user['id']) {
        jsonResponse(['error' => 'AI変更案の有効期限が切れています。もう一度指示してください。'], 404);
    }
    $conversationId = (int) ($pending['conversation_id'] ?? 0);
    $turnId = (int) ($pending['turn_id'] ?? 0);
    session_write_close();

    try {
        $repository = new AiChatRepository($pdo);
        if (empty($body['confirm'])) {
            $saved = $repository->updateTurnAnswer(
                $conversationId,
                $turnId,
                (int) $user['id'],
                '変更案は実行しませんでした。',
                $expectedPasswordFingerprint
            );
            $saved['turn']['actions'] = [];
            jsonResponse(['result' => $saved['turn'], 'conversation' => $saved['conversation']]);
        }

        $result = (new AiPageActionService($pdo, null, baseUrl()))->executePending(
            $pending,
            $user,
            $expectedPasswordFingerprint
        );
        $actions = is_array($result['actions'] ?? null) ? $result['actions'] : [];
        $affectedPublicationsByRoot = [];
        foreach ($actions as $executedAction) {
            if (!is_array($executedAction) || (string) ($executedAction['type'] ?? '') !== 'page_updated') {
                continue;
            }
            $updatedPageId = (int) ($executedAction['page_id'] ?? 0);
            foreach ((new PublicPageSharing($pdo))->affectedPublicationsForUser(
                $updatedPageId,
                $user,
                $expectedPasswordFingerprint
            ) as $publication) {
                $affectedPublicationsByRoot[(int) $publication['root_page_id']] = $publication;
            }
        }
        $saved = $repository->updateTurnAnswer(
            $conversationId,
            $turnId,
            (int) $user['id'],
            (string) ($result['answer'] ?? 'AI変更を実行しました。'),
            $expectedPasswordFingerprint,
            $result
        );
        $saved['turn']['actions'] = !empty($saved['turn_accessible']) ? $actions : [];
        jsonResponse([
            'result' => $saved['turn'],
            'conversation' => $saved['conversation'],
            'affected_publications' => array_values($affectedPublicationsByRoot),
        ]);
    } catch (InvalidArgumentException $exception) {
        jsonResponse(['error' => $exception->getMessage()], 422);
    } catch (Throwable $exception) {
        if ((int) $exception->getCode() === 401) {
            jsonResponse(['error' => $exception->getMessage()], 401);
        }
        error_log('OpenConcept AI page action error: ' . $exception->getMessage());
        jsonResponse(aiFailureResponsePayload(
            $exception,
            'AI変更を実行できませんでした。最新のページを確認してもう一度お試しください。'
        ), 502);
    }
}

if ($action === 'ai-search' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $mode = (string) ($body['mode'] ?? 'search');
    $question = (string) ($body['question'] ?? '');
    $pageId = (int) ($body['page_id'] ?? 0);
    $conversationId = (int) ($body['conversation_id'] ?? 0);
    $editorContext = is_array($body['editor_context'] ?? null) ? $body['editor_context'] : [];
    $expectedUpdatedAt = cleanText((string) ($body['page_updated_at'] ?? ''), 40);
    if (!in_array($mode, ['search', 'page-summary'], true)) {
        jsonResponse(['error' => 'AI検索の処理方法が正しくありません。'], 422);
    }
    $now = time();
    $windowSeconds = 60;
    $limit = max(2, min(30, (int) (getenv('OPENCONCEPT_AI_SEARCH_RATE_LIMIT') ?: 8)));
    $attempts = array_values(array_filter(
        is_array($_SESSION['ai_search_attempts'] ?? null) ? $_SESSION['ai_search_attempts'] : [],
        static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - $windowSeconds
    ));
    if (count($attempts) >= $limit) {
        header('Retry-After: 60');
        jsonResponse(['error' => 'AI検索の利用が続いています。1分ほど待ってからもう一度お試しください。'], 429);
    }
    $attempts[] = $now;
    $_SESSION['ai_search_attempts'] = $attempts;

    $repository = new AiChatRepository($pdo);
    $history = [];
    if ($conversationId > 0) {
        try {
            $history = $repository->historyForModel(
                $conversationId,
                (int) $user['id'],
                $expectedPasswordFingerprint
            );
        } catch (InvalidArgumentException $exception) {
            jsonResponse(['error' => $exception->getMessage()], 404);
        }
    }
    session_write_close();

    try {
        $service = new AiSearchService($pdo, null, baseUrl());
        $result = null;
        if ($mode === 'search') {
            $result = (new AiPageActionService($pdo, null, baseUrl()))->handle(
                $question,
                $user,
                $pageId,
                $expectedPasswordFingerprint,
                $editorContext,
                $history,
                $expectedUpdatedAt
            );
        }
        $result ??= $mode === 'page-summary'
            ? $service->summarizePage($pageId, $user, $expectedPasswordFingerprint, $history)
            : $service->search($question, $user, $expectedPasswordFingerprint, $history, $pageId);
        $pendingAction = is_array($result['pending_action'] ?? null) ? $result['pending_action'] : null;
        unset($result['pending_action']);
        $actions = is_array($result['actions'] ?? null) ? $result['actions'] : [];
        if ($conversationId < 1) {
            $conversation = $repository->createForUser(
                (int) $user['id'],
                $expectedPasswordFingerprint
            );
            $conversationId = (int) $conversation['id'];
        }
        $saved = $repository->appendTurn(
            $conversationId,
            (int) $user['id'],
            $result,
            $expectedPasswordFingerprint
        );
        $turnAccessible = !empty($saved['turn_accessible']);
        if ($turnAccessible && $pendingAction !== null) {
            $actions[] = storePendingAiPageAction($pendingAction, $conversationId, (int) $saved['turn']['id']);
        }
        $saved['turn']['actions'] = $turnAccessible ? $actions : [];
        jsonResponse(['result' => $saved['turn'], 'conversation' => $saved['conversation']]);
    } catch (InvalidArgumentException $exception) {
        jsonResponse(['error' => $exception->getMessage()], 422);
    } catch (Throwable $exception) {
        if ((int) $exception->getCode() === 401) {
            jsonResponse(['error' => $exception->getMessage()], 401);
        }
        error_log('OpenConcept AI search error: ' . $exception->getMessage());
        jsonResponse(aiFailureResponsePayload(
            $exception,
            'AI検索を完了できませんでした。以下のエラー詳細を確認してください。'
        ), 502);
    }
}

if ($action === 'file-content' && $method === 'GET') {
    $ownsPageSnapshot = beginConsistentPageRead($pdo);
    $snapshotUser = currentUser($pdo);
    if (!$snapshotUser) {
        if ($ownsPageSnapshot && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'ログインが必要です。'], 401);
    }
    $user = $snapshotUser;
    $id = max(1, (int) ($_GET['id'] ?? 0));
    $statement = $pdo->prepare('SELECT * FROM files WHERE id = ?');
    $statement->execute([$id]);
    $file = $statement->fetch();
    if (!$file) {
        if ($ownsPageSnapshot && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'ファイルが見つかりません。'], 404);
    }
    if (!canViewFile($pdo, $user, $file)) {
        if ($ownsPageSnapshot && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'このファイルを閲覧する権限がありません。'], 403);
    }

    $path = uploadStoragePath() . '/' . basename((string) $file['stored_name']);
    if ($ownsPageSnapshot) {
        $pdo->commit();
    }
    if (!is_file($path)) {
        jsonResponse(['error' => '保存されたファイルが見つかりません。'], 404);
    }

    $originalName = str_replace(["\r", "\n", '"'], '', (string) $file['original_name']);
    $disposition = in_array($file['category'], ['pdf', 'markdown', 'text', 'image'], true) ? 'inline' : 'attachment';
    session_write_close();
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . filesize($path));
    header("Content-Disposition: {$disposition}; filename*=UTF-8''" . rawurlencode($originalName));
    header('Cache-Control: private, max-age=3600');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    readfile($path);
    exit;
}

if ($action === 'upload-file' && $method === 'POST') {
    requireCsrf();
    $pageId = max(0, (int) ($_POST['page_id'] ?? 0));
    $pageTitle = null;
    $ownsPageSnapshot = beginConsistentPageRead($pdo);
    $snapshotUser = currentUser($pdo);
    if (!$snapshotUser) {
        if ($ownsPageSnapshot && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'ログインが必要です。'], 401);
    }
    $user = $snapshotUser;
    if ($pageId > 0) {
        $pageStatement = $pdo->prepare('SELECT * FROM pages WHERE id = ? AND archived_at IS NULL');
        $pageStatement->execute([$pageId]);
        $page = $pageStatement->fetch();
        if (!$page) {
            if ($ownsPageSnapshot && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['error' => 'アップロード先のページが見つかりません。'], 404);
        }
        if (!canEditPage($pdo, $user, $page)) {
            if ($ownsPageSnapshot && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['error' => 'このページにファイルを追加する権限がありません。'], 403);
        }
        $pageTitle = (string) $page['title'];
    } elseif (!canCreatePage($user)) {
        if ($ownsPageSnapshot && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'ファイルをアップロードする権限がありません。'], 403);
    }
    if ($ownsPageSnapshot) {
        $pdo->commit();
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        jsonResponse(['error' => 'アップロードするファイルを選択してください。'], 422);
    }
    $upload = $_FILES['file'];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $message = in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'ファイルサイズがサーバーの上限を超えています。'
            : 'ファイルをアップロードできませんでした。';
        jsonResponse(['error' => $message], 422);
    }

    $maxMegabytes = uploadLimitMegabytes();
    $size = (int) ($upload['size'] ?? 0);
    if ($size < 1 || $size > $maxMegabytes * 1024 * 1024) {
        jsonResponse(['error' => "ファイルは{$maxMegabytes}MB以下にしてください。"], 422);
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    if (!is_uploaded_file($temporaryPath)) {
        jsonResponse(['error' => '正しいアップロードファイルではありません。'], 422);
    }
    $originalName = cleanText(basename(str_replace('\\', '/', (string) ($upload['name'] ?? ''))), 255);
    if ($originalName === '') {
        jsonResponse(['error' => 'ファイル名を確認してください。'], 422);
    }
    $inspected = inspectUploadedFile($temporaryPath, $originalName);
    if (!$inspected) {
        jsonResponse(['error' => '対応形式は PDF、Excel、Word、Markdown、TXT、PNG・JPEG・GIF・WebP 画像です。'], 422);
    }

    $uploadDirectory = uploadStoragePath();
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0770, true) && !is_dir($uploadDirectory)) {
        jsonResponse(['error' => 'ファイル保存先を作成できません。'], 500);
    }
    $storedName = bin2hex(random_bytes(24)) . '.' . $inspected['extension'];
    $destination = $uploadDirectory . '/' . $storedName;
    if (!move_uploaded_file($temporaryPath, $destination)) {
        jsonResponse(['error' => 'ファイルを保存できませんでした。'], 500);
    }

    try {
        beginPageWriteTransaction($pdo);
        $uploadContext = lockedUploadWriteContext(
            $pdo,
            $user,
            $pageId,
            $expectedPasswordFingerprint
        );
        $user = $uploadContext['user'];
        if (is_array($uploadContext['page'])) {
            $pageTitle = (string) $uploadContext['page']['title'];
        }

        $insert = $pdo->prepare('INSERT INTO files (original_name, stored_name, mime_type, category, size_bytes, uploaded_by, page_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$originalName, $storedName, $inspected['mime'], $inspected['category'], $size, (int) $user['id'], $pageId ?: null]);
        $fileId = (int) $pdo->lastInsertId();
        audit($pdo, (int) $user['id'], 'file_uploaded', 'file', $fileId, ['name' => $originalName, 'category' => $inspected['category'], 'page_id' => $pageId ?: null]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        @unlink($destination);
        if ($exception instanceof RuntimeException && in_array((int) $exception->getCode(), [401, 403, 404, 409], true)) {
            jsonResponse(['error' => $exception->getMessage()], (int) $exception->getCode());
        }
        throw $exception;
    }

    jsonResponse(['ok' => true, 'file' => [
        'id' => $fileId,
        'original_name' => $originalName,
        'mime_type' => $inspected['mime'],
        'category' => $inspected['category'],
        'size_bytes' => $size,
        'uploaded_by' => (int) $user['id'],
        'uploader_name' => $user['name'],
        'page_id' => $pageId ?: null,
        'page_title' => $pageTitle,
        'references' => $pageId > 0 ? [['page_id' => $pageId, 'page_title' => $pageTitle]] : [],
        'reference_count' => $pageId > 0 ? 1 : 0,
        'referenced' => $pageId > 0,
        'created_at' => date('Y-m-d H:i:s'),
        'can_delete' => true,
    ]], 201);
}

if ($action === 'delete-file' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $id = (int) ($body['id'] ?? 0);
    if ($id < 1) {
        jsonResponse(['error' => '削除するファイルを指定してください。'], 422);
    }
    try {
        $deleted = deleteFileRecord($pdo, $user, $id, $expectedPasswordFingerprint);
    } catch (RuntimeException $exception) {
        $status = in_array((int) $exception->getCode(), [401, 403, 404, 409], true)
            ? (int) $exception->getCode()
            : 500;
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        $message = $status === 500 ? 'ファイルを削除できませんでした。' : $exception->getMessage();
        jsonResponse(['error' => $message], $status);
    }
    $affectedPublications = is_int($deleted['page_id'] ?? null) && $deleted['page_id'] > 0
        ? (new PublicPageSharing($pdo))->affectedPublicationsForUser(
            (int) $deleted['page_id'],
            $user,
            $expectedPasswordFingerprint
        )
        : [];
    jsonResponse([
        'ok' => true,
        'file' => $deleted,
        'affected_publications' => $affectedPublications,
    ]);
}

if ($action === 'invite-member' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    $body = bodyJson();
    $name = cleanText((string) ($body['name'] ?? ''), 120);
    $email = lowerText(cleanText((string) ($body['email'] ?? ''), 190));
    $department = cleanText((string) ($body['department'] ?? ''), 120);
    $allowedRoles = ['admin', 'content_admin', 'editor', 'author', 'commenter', 'viewer'];
    $role = in_array($body['role'] ?? '', $allowedRoles, true) ? $body['role'] : 'viewer';
    $currentPassword = (string) ($body['current_password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => '氏名と正しいメールアドレスを入力してください。'], 422);
    }
    if ($department === '') {
        jsonResponse(['error' => '部署を選択するか、新しい部署名を入力してください。'], 422);
    }
    $password = temporaryPassword();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $colors = ['#516C65', '#76658C', '#9A654A', '#496B88', '#8B665E', '#5C6D49'];
    $color = $colors[random_int(0, count($colors) - 1)];
    try {
        beginPageWriteTransaction($pdo);
        $administratorSet = requireLockedAdministratorSetForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        $liveAdmin = $administratorSet['actor'];
        if ($role === 'admin') {
            requireCurrentPasswordForSensitiveAction($liveAdmin, $currentPassword);
        }
        $duplicateSql = 'SELECT id, active, role FROM users WHERE email = ?';
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $duplicateSql .= ' FOR UPDATE';
        }
        $duplicate = $pdo->prepare($duplicateSql);
        $duplicate->execute([$email]);
        $existingAccount = $duplicate->fetch();
        if ($existingAccount && !(bool) $existingAccount['active'] && $existingAccount['role'] === 'suspended') {
            throw new RuntimeException('このメールアドレスのアカウントは停止されているため、再登録できません。', 422);
        }
        if ($existingAccount) {
            throw new RuntimeException('このメールアドレスは既に登録されています。', 422);
        }

        $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, avatar_color, role, department, active, must_change_password, invited_at) VALUES (?, ?, ?, ?, ?, ?, 1, 1, CURRENT_TIMESTAMP)');
        $insert->execute([$name, $email, $passwordHash, $color, $role, $department]);
        $memberId = (int) $pdo->lastInsertId();
        audit($pdo, (int) $liveAdmin['id'], 'member_invited', 'user', $memberId, [
            'email' => $email,
            'delivery_deferred' => true,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $exception instanceof RuntimeException
            && in_array((int) $exception->getCode(), [401, 403, 422], true)
            ? (int) $exception->getCode()
            : ($exception instanceof PDOException ? 422 : 500);
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse(['error' => $status === 500 ? 'メンバーを招待できませんでした。' : $exception->getMessage()], $status);
    }

    $mail = Mailer::sendInvitation($name, $email, $password, baseUrl(), (string) $liveAdmin['name'], dirname(__DIR__) . '/storage');
    jsonResponse([
        'ok' => true,
        'mail_sent' => $mail['sent'],
        'mail_transport' => $mail['transport'],
        'mail_error' => $mail['error'],
        'temporary_password' => $mail['sent'] ? null : $password,
        'member' => ['id' => $memberId, 'name' => $name, 'email' => $email, 'avatar_color' => $color, 'avatar_kind' => 'initials', 'avatar_value' => '', 'role' => $role, 'department' => $department, 'must_change_password' => true, 'invited_at' => date('Y-m-d H:i:s'), 'last_login_at' => null, 'initial_login_pending' => true],
    ], 201);
}

if ($action === 'resend-invitation' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    $body = bodyJson();
    $memberId = (int) ($body['member_id'] ?? 0);
    $currentPassword = (string) ($body['current_password'] ?? '');
    if ($memberId < 1) {
        jsonResponse(['error' => '再送するメンバーを確認してください。'], 422);
    }

    $password = temporaryPassword();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $manager = new InvitationManager($pdo);
    try {
        beginPageWriteTransaction($pdo);
        $administratorSet = requireLockedAdministratorSetForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        $liveAdmin = $administratorSet['actor'];
        $lockedMember = lockedApplicationUserForUpdate($pdo, $memberId);
        if ($lockedMember === null) {
            throw new RuntimeException('このメンバーは初回ログイン待ちではありません。画面を更新してください。', 409);
        }
        if ((string) ($lockedMember['role'] ?? '') === 'admin') {
            requireCurrentPasswordForSensitiveAction($liveAdmin, $currentPassword);
        }
        $member = $manager->replaceTemporaryPassword($memberId, $passwordHash);
        if (!$member) {
            throw new RuntimeException('このメンバーは初回ログイン待ちではありません。画面を更新してください。', 409);
        }
        audit($pdo, (int) $liveAdmin['id'], 'member_invitation_resent', 'user', $memberId, [
            'delivery_deferred' => true,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $exception instanceof RuntimeException
            && in_array((int) $exception->getCode(), [401, 403, 409, 422], true)
            ? (int) $exception->getCode()
            : 500;
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse(['error' => $status === 500 ? '招待を再送できませんでした。' : $exception->getMessage()], $status);
    }

    $mail = Mailer::sendInvitation(
        (string) $member['name'],
        (string) $member['email'],
        $password,
        baseUrl(),
        (string) $liveAdmin['name'],
        dirname(__DIR__) . '/storage',
        true
    );
    jsonResponse([
        'ok' => true,
        'mail_sent' => $mail['sent'],
        'mail_transport' => $mail['transport'],
        'mail_error' => $mail['error'],
        'temporary_password' => $mail['sent'] ? null : $password,
        'member_id' => $memberId,
        'email' => $member['email'],
        'invited_at' => $member['invited_at'],
    ]);
}

if ($action === 'delete-pending-invitation' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    $body = bodyJson();
    $memberId = (int) ($body['member_id'] ?? 0);
    $currentPassword = (string) ($body['current_password'] ?? '');
    if ($memberId < 1) {
        jsonResponse(['error' => '削除するメンバーを確認してください。'], 422);
    }

    try {
        beginPageWriteTransaction($pdo);
        $administratorSet = requireLockedAdministratorSetForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        $liveAdmin = $administratorSet['actor'];
        $lockedMember = lockedApplicationUserForUpdate($pdo, $memberId);
        if ($lockedMember === null) {
            throw new RuntimeException('このメンバーは初回ログイン待ちではありません。画面を更新してください。', 409);
        }
        if ((string) ($lockedMember['role'] ?? '') === 'admin') {
            requireCurrentPasswordForSensitiveAction($liveAdmin, $currentPassword);
        }
        $deleted = (new InvitationManager($pdo))->deletePending($memberId);
        if (!$deleted) {
            throw new RuntimeException('このメンバーは初回ログイン待ちではありません。画面を更新してください。', 409);
        }
        audit($pdo, (int) $liveAdmin['id'], 'pending_invitation_deleted', 'user', $memberId);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $exception instanceof RuntimeException
            && in_array((int) $exception->getCode(), [401, 403, 409, 422], true)
            ? (int) $exception->getCode()
            : ($exception instanceof PDOException ? 409 : 500);
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        $message = $status === 500
            ? '招待を削除できませんでした。'
            : ($exception instanceof PDOException
                ? '関連データがあるため、この招待を削除できません。'
                : $exception->getMessage());
        jsonResponse(['error' => $message], $status);
    }

    jsonResponse(['ok' => true, 'member_id' => $memberId, 'email_removed' => true]);
}

if ($action === 'update-member-name' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    $body = bodyJson();
    $memberId = (int) ($body['member_id'] ?? 0);
    $name = cleanText((string) ($body['name'] ?? ''), 120);
    if ($memberId < 1 || $name === '') {
        jsonResponse(['error' => 'アカウントと名前を確認してください。'], 422);
    }

    try {
        beginPageWriteTransaction($pdo);
        $administratorSet = requireLockedAdministratorSetForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        $liveAdmin = $administratorSet['actor'];
        $lockedMember = lockedApplicationUserForUpdate($pdo, $memberId);
        if ($lockedMember === null || (string) ($lockedMember['role'] ?? '') === 'system') {
            throw new RuntimeException('名前を変更できるアカウントが見つかりません。', 404);
        }
        $updatedMember = updateMemberName($pdo, $liveAdmin, $memberId, $name);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $exception instanceof RuntimeException
            && in_array((int) $exception->getCode(), [401, 403, 404, 422], true)
            ? (int) $exception->getCode()
            : 500;
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse(['error' => $status === 500 ? '名前を変更できませんでした。' : $exception->getMessage()], $status);
    }
    jsonResponse(['ok' => true, 'member_id' => $memberId, 'name' => $name, 'member' => $updatedMember]);
}

if ($action === 'update-member-role' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    $body = bodyJson();
    $memberId = (int) ($body['member_id'] ?? 0);
    $allowedRoles = ['admin', 'content_admin', 'editor', 'author', 'commenter', 'viewer'];
    $role = (string) ($body['role'] ?? '');
    $currentPassword = (string) ($body['current_password'] ?? '');
    if ($memberId < 1 || !in_array($role, $allowedRoles, true)) {
        jsonResponse(['error' => 'メンバーと役割を確認してください。'], 422);
    }
    try {
        beginPageWriteTransaction($pdo);
        $administratorSet = requireLockedAdministratorSetForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        $liveAdmin = $administratorSet['actor'];
        $member = lockedApplicationUserForUpdate($pdo, $memberId);
        if ($member === null
            || !(bool) ($member['active'] ?? false)
            || in_array((string) ($member['role'] ?? ''), ['system', 'suspended'], true)) {
            throw new RuntimeException('役割を変更できるメンバーが見つかりません。', 404);
        }
        if ((string) $member['role'] === 'admin' || $role === 'admin') {
            requireCurrentPasswordForSensitiveAction($liveAdmin, $currentPassword);
        }
        $updatedMember = updateMemberRole($pdo, $liveAdmin, $memberId, $role);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $exception instanceof RuntimeException
            && in_array((int) $exception->getCode(), [401, 403, 404, 409, 422], true)
            ? (int) $exception->getCode()
            : 500;
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse(['error' => $status === 500 ? '役割を変更できませんでした。' : $exception->getMessage()], $status);
    }
    jsonResponse(['ok' => true, 'member_id' => $memberId, 'role' => $role, 'member' => $updatedMember]);
}

if ($action === 'suspend-member' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    $body = bodyJson();
    $memberId = (int) ($body['member_id'] ?? 0);
    $currentPassword = (string) ($body['current_password'] ?? '');
    if ($memberId < 1 || $memberId === (int) $user['id']) {
        jsonResponse(['error' => '停止するメンバーを確認してください。'], 422);
    }

    try {
        beginPageWriteTransaction($pdo);
        $administratorSet = requireLockedAdministratorSetForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        $liveAdmin = $administratorSet['actor'];
        $lockedMember = lockedApplicationUserForUpdate($pdo, $memberId);
        if ($memberId === (int) $liveAdmin['id']
            || $lockedMember === null) {
            throw new RuntimeException('停止できるアカウントが見つかりません。', 404);
        }
        if ((string) ($lockedMember['role'] ?? '') === 'admin') {
            requireCurrentPasswordForSensitiveAction($liveAdmin, $currentPassword);
        }
        $member = suspendMemberAccount($pdo, $liveAdmin, $memberId);
        if (!$member) {
            throw new RuntimeException('停止できるアカウントが見つかりません。', 404);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $exception instanceof RuntimeException
            && in_array((int) $exception->getCode(), [401, 403, 404, 409, 422], true)
            ? (int) $exception->getCode()
            : 500;
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse(['error' => $status === 500 ? 'アカウントを停止できませんでした。' : $exception->getMessage()], $status);
    }
    jsonResponse([
        'ok' => true,
        'member_id' => $memberId,
        'status' => 'suspended',
        'member' => $member,
    ]);
}

if ($action === 'reactivate-member' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    $body = bodyJson();
    $memberId = (int) ($body['member_id'] ?? 0);
    $role = (string) ($body['role'] ?? '');
    $currentPassword = (string) ($body['current_password'] ?? '');
    $allowedRoles = ['admin', 'content_admin', 'editor', 'author', 'commenter', 'viewer'];
    if ($memberId < 1 || !in_array($role, $allowedRoles, true)) {
        jsonResponse(['error' => '復活するアカウントと権限を確認してください。'], 422);
    }

    $password = temporaryPassword();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    try {
        beginPageWriteTransaction($pdo);
        $administratorSet = requireLockedAdministratorSetForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        $liveAdmin = $administratorSet['actor'];
        if ($role === 'admin') {
            requireCurrentPasswordForSensitiveAction($liveAdmin, $currentPassword);
        }
        if (lockedApplicationUserForUpdate($pdo, $memberId) === null) {
            throw new RuntimeException('復活できる停止中アカウントが見つかりません。', 404);
        }
        $member = reactivateMemberAccount($pdo, $liveAdmin, $memberId, $role, $passwordHash);
        if (!$member) {
            throw new RuntimeException('復活できる停止中アカウントが見つかりません。', 404);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $exception instanceof RuntimeException
            && in_array((int) $exception->getCode(), [401, 403, 404, 409, 422], true)
            ? (int) $exception->getCode()
            : 500;
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse(['error' => $status === 500 ? 'アカウントを復活できませんでした。' : $exception->getMessage()], $status);
    }

    $mail = Mailer::sendInvitation(
        (string) $member['name'],
        (string) $member['email'],
        $password,
        baseUrl(),
        (string) $liveAdmin['name'],
        dirname(__DIR__) . '/storage',
        true
    );
    jsonResponse([
        'ok' => true,
        'mail_sent' => $mail['sent'],
        'mail_transport' => $mail['transport'],
        'mail_error' => $mail['error'],
        'temporary_password' => $mail['sent'] ? null : $password,
        'member' => $member,
    ]);
}

if ($action === 'reset-member-password' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    $body = bodyJson();
    $memberId = (int) ($body['member_id'] ?? 0);
    $currentPassword = (string) ($body['current_password'] ?? '');
    if ($memberId < 1 || $memberId === (int) $user['id']) {
        jsonResponse(['error' => 'パスワードを再設定するメンバーを確認してください。'], 422);
    }

    $password = temporaryPassword();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    try {
        beginPageWriteTransaction($pdo);
        $administratorSet = requireLockedAdministratorSetForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        $liveAdmin = $administratorSet['actor'];
        requireCurrentPasswordForSensitiveAction($liveAdmin, $currentPassword);
        $member = resetMemberPassword($pdo, $liveAdmin, $memberId, $passwordHash);
        if (!$member) {
            throw new RuntimeException('パスワードを再設定できるメンバーが見つかりません。', 404);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $exception instanceof RuntimeException
            && in_array((int) $exception->getCode(), [401, 403, 404, 409, 422], true)
            ? (int) $exception->getCode()
            : 500;
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse(['error' => $status === 500 ? 'パスワードを再設定できませんでした。' : $exception->getMessage()], $status);
    }

    $mail = Mailer::sendInvitation(
        (string) $member['name'],
        (string) $member['email'],
        $password,
        baseUrl(),
        (string) $liveAdmin['name'],
        dirname(__DIR__) . '/storage',
        true
    );
    jsonResponse([
        'ok' => true,
        'mail_sent' => $mail['sent'],
        'mail_transport' => $mail['transport'],
        'mail_error' => $mail['error'],
        'temporary_password' => $mail['sent'] ? null : $password,
        'member' => $member,
    ]);
}

if ($action === 'update-organization-name' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    $organizationName = cleanText((string) (bodyJson()['organization_name'] ?? ''), 120);
    if ($organizationName === '') {
        jsonResponse(['error' => '組織名を入力してください。'], 422);
    }

    $affectedPublications = [];
    try {
        beginPageWriteTransaction($pdo);
        $liveAdmin = requireLockedApplicationAdminForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        $organizationChanged = setting($pdo, 'organization_name', '') !== $organizationName;
        setSetting($pdo, 'organization_name', $organizationName);
        if ($organizationChanged) {
            $enabledRootIds = array_map(
                'intval',
                $pdo->query('SELECT root_page_id FROM page_public_shares WHERE enabled = 1 ORDER BY root_page_id')
                    ->fetchAll(PDO::FETCH_COLUMN)
            );
            $affectedPublications = $affectedPublicationsForPageIds($enabledRootIds);
        }
        audit($pdo, (int) $liveAdmin['id'], 'organization_name_updated', 'system', 1);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $exception instanceof RuntimeException
            && in_array((int) $exception->getCode(), [401, 403], true)
            ? (int) $exception->getCode()
            : 500;
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse(['error' => $status === 500 ? '組織名を更新できませんでした。' : $exception->getMessage()], $status);
    }
    jsonResponse([
        'ok' => true,
        'organization_name' => $organizationName,
        'affected_publications' => $affectedPublications,
    ]);
}

if ($action === 'plugins' && $method === 'GET') {
    requireRole($user, ['admin']);

    $catalog = PluginCatalog::official(dirname(__DIR__) . '/plugins');
    $catalogEntries = [];
    $catalogError = null;
    if ($catalog->isConfigured()) {
        try {
            $catalogEntries = $catalog->entries();
        } catch (Throwable $exception) {
            $catalogError = $exception->getMessage();
        }
    }

    $installed = $pluginManager->summaries();
    $databaseAdapterCoordinator = new DatabaseAdapterCoordinator($database, dirname(__DIR__));
    $runtimeEnvironment = (new DeploymentRuntimeInspector(dirname(__DIR__)))->inspect($database->backendId());
    foreach ($installed as &$installedPlugin) {
        $adapterId = match ((string) ($installedPlugin['id'] ?? '')) {
            'database-mysql-adapter' => 'mysql',
            'database-postgresql-adapter' => 'postgresql',
            default => null,
        };
        if ($adapterId !== null) {
            $installedPlugin['database_adapter'] = $databaseAdapterCoordinator->status($adapterId);
        }
    }
    unset($installedPlugin);
    $installedIds = array_fill_keys(array_column($installed, 'id'), true);
    $available = array_values(array_filter(
        $catalogEntries,
        static fn (array $entry): bool => !isset($installedIds[$entry['id']])
    ));

    jsonResponse([
        'installed' => $installed,
        'available' => $available,
        'catalog_configured' => $catalog->isConfigured(),
        'catalog_error' => $catalogError,
        'runtime_environment' => $runtimeEnvironment,
    ]);
}

if ($action === 'download-plugin' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    $pluginId = (string) (bodyJson()['plugin_id'] ?? '');
    if (!PluginManager::isValidId($pluginId)) {
        jsonResponse(['error' => 'ダウンロードするプラグインを確認してください。'], 422);
    }

    $catalog = PluginCatalog::official(dirname(__DIR__) . '/plugins');
    if (!$catalog->isConfigured()) {
        jsonResponse(['error' => 'プラグインカタログが設定されていません。'], 503);
    }

    $liveAdmin = null;
    try {
        $plugin = $catalog->install(
            $pluginId,
            static function (array $_plugin) use (
                $pdo,
                $user,
                $expectedPasswordFingerprint,
                &$liveAdmin
            ): void {
                beginPageWriteTransaction($pdo);
                $liveAdmin = requireLockedApplicationAdminForWrite(
                    $pdo,
                    $user,
                    $expectedPasswordFingerprint
                );
            },
            static function (array $installedPlugin) use (
                $pdo,
                $pluginId,
                &$liveAdmin
            ): void {
                if (!is_array($liveAdmin)) {
                    throw new RuntimeException('管理者の認証状態を確認できません。', 401);
                }
                // A downloaded plugin never executes until an administrator
                // explicitly enables it in a subsequent request.
                setSetting($pdo, 'plugin.' . $pluginId . '.enabled', '0');
                audit($pdo, (int) $liveAdmin['id'], 'plugin_downloaded', 'plugin', 0, [
                    'plugin_id' => $pluginId,
                    'version' => $installedPlugin['version'],
                    'enabled' => false,
                ]);
                $pdo->commit();
            }
        );
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $exception instanceof RuntimeException
            && in_array((int) $exception->getCode(), [401, 403], true)
            ? (int) $exception->getCode()
            : (str_contains($exception->getMessage(), 'already installed') ? 409 : 422);
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse(['error' => 'プラグインをダウンロードできませんでした：' . $exception->getMessage()], $status);
    }
    jsonResponse(['ok' => true, 'plugin' => $plugin], 201);
}

if ($action === 'toggle-plugin' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin']);
    $body = bodyJson();
    $pluginId = (string) ($body['plugin_id'] ?? '');
    $enabled = $body['enabled'] ?? null;
    if (!PluginManager::isValidId($pluginId) || !is_bool($enabled)) {
        jsonResponse(['error' => 'プラグインと有効状態を確認してください。'], 422);
    }

    $plugin = $pluginManager->plugin($pluginId);
    if ($plugin === null) {
        jsonResponse(['error' => 'インストール済みのプラグインが見つかりません。'], 404);
    }

    try {
        beginPageWriteTransaction($pdo);
        $liveAdmin = requireLockedApplicationAdminForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        setSetting($pdo, 'plugin.' . $pluginId . '.enabled', $enabled ? '1' : '0');
        audit($pdo, (int) $liveAdmin['id'], $enabled ? 'plugin_enabled' : 'plugin_disabled', 'plugin', 0, [
            'plugin_id' => $pluginId,
            'version' => $plugin['version'],
            'enabled' => $enabled,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = $exception instanceof RuntimeException
            && in_array((int) $exception->getCode(), [401, 403], true)
            ? (int) $exception->getCode()
            : 500;
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse(['error' => $status === 500 ? 'プラグイン設定を更新できませんでした。' : $exception->getMessage()], $status);
    }
    jsonResponse([
        'ok' => true,
        'plugin_id' => $pluginId,
        'enabled' => $enabled,
        'reload_required' => true,
    ]);
}

if ($action === 'public-share' && $method === 'GET') {
    $rawPageId = $_GET['id'] ?? null;
    if (!is_string($rawPageId) || preg_match('/^[1-9][0-9]*$/D', $rawPageId) !== 1) {
        jsonResponse(['error' => 'ページIDの指定が正しくありません。'], 422);
    }
    $pageId = (int) $rawPageId;
    try {
        $publicShare = (new PublicPageSharing($pdo))->stateForUser(
            $pageId,
            $user,
            $expectedPasswordFingerprint
        );
        jsonResponse(['public_share' => $publicShare]);
    } catch (Throwable $exception) {
        notifyStaticPublicationPermissionFailure($pdo, $exception);
        $status = in_array((int) $exception->getCode(), [401, 403, 404, 409, 422], true)
            ? (int) $exception->getCode()
            : 500;
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse([
            'error' => $status === 500
                ? ($exception instanceof StaticPublicationException
                    && $exception->publicCode === 'publication_permissions_unsafe'
                    ? $exception->getMessage()
                    : 'Web公開設定を取得できませんでした。')
                : $exception->getMessage(),
            'code' => $exception instanceof StaticPublicationException
                ? $exception->publicCode
                : ($status === 409 ? 'publication_conflict' : null),
        ], $status);
    }
}

if ($action === 'public-share' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $pageId = $body['page_id'] ?? null;
    $enabled = $body['enabled'] ?? null;
    $selectedPageIds = $body['selected_page_ids'] ?? null;
    $expectedRevision = $body['expected_revision'] ?? null;
    $publicSlug = $body['public_slug'] ?? null;
    if (!is_int($pageId)
        || $pageId < 1
        || !is_bool($enabled)
        || !is_array($selectedPageIds)
        || ($publicSlug !== null && !is_string($publicSlug))
        || ($expectedRevision !== null && (!is_int($expectedRevision) || $expectedRevision < 1))) {
        jsonResponse(['error' => 'Web公開設定の指定が正しくありません。'], 422);
    }
    try {
        $publicShare = (new PublicPageSharing($pdo))->updateForUser(
            $pageId,
            $enabled,
            $selectedPageIds,
            $expectedRevision,
            $user,
            $expectedPasswordFingerprint,
            null,
            $publicSlug
        );
        jsonResponse(['ok' => true, 'public_share' => $publicShare]);
    } catch (Throwable $exception) {
        notifyStaticPublicationPermissionFailure($pdo, $exception);
        $status = in_array((int) $exception->getCode(), [401, 403, 404, 409, 422], true)
            ? (int) $exception->getCode()
            : 500;
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse([
            'error' => $status === 500
                ? ($exception instanceof StaticPublicationException
                    && $exception->publicCode === 'publication_permissions_unsafe'
                    ? $exception->getMessage()
                    : 'Web公開設定を保存できませんでした。')
                : $exception->getMessage(),
            'code' => $exception instanceof StaticPublicationException
                ? $exception->publicCode
                : ($status === 409 ? 'publication_conflict' : null),
        ], $status);
    }
}

if ($action === 'public-share-refresh' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $rootPageId = $body['root_page_id'] ?? null;
    $expectedRevision = $body['expected_revision'] ?? null;
    if (!is_int($rootPageId) || $rootPageId < 1
        || !is_int($expectedRevision) || $expectedRevision < 1) {
        jsonResponse(['error' => '静的サイト更新の指定が正しくありません。'], 422);
    }
    try {
        $publicShare = (new PublicPageSharing($pdo))->refreshForUser(
            $rootPageId,
            $expectedRevision,
            $user,
            $expectedPasswordFingerprint
        );
        jsonResponse(['ok' => true, 'public_share' => $publicShare]);
    } catch (Throwable $exception) {
        notifyStaticPublicationPermissionFailure($pdo, $exception);
        $status = in_array((int) $exception->getCode(), [401, 403, 404, 409, 422], true)
            ? (int) $exception->getCode()
            : 500;
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        jsonResponse([
            'error' => $status === 500
                ? ($exception instanceof StaticPublicationException
                    && $exception->publicCode === 'publication_permissions_unsafe'
                    ? $exception->getMessage()
                    : '静的サイトを更新できませんでした。')
                : $exception->getMessage(),
            'code' => $exception instanceof StaticPublicationException
                ? $exception->publicCode
                : ($status === 409 ? 'publication_conflict' : null),
        ], $status);
    }
}

if ($action === 'bootstrap') {
    $ownsPageSnapshot = beginConsistentPageRead($pdo);
    $snapshotUser = currentUser($pdo);
    if (!$snapshotUser) {
        if ($ownsPageSnapshot && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'ログインが必要です。'], 401);
    }
    $user = $snapshotUser;
    $pages = $pdo->query(<<<'SQL'
SELECT p.id, p.parent_id, p.sort_order, p.title, p.icon, p.cover, p.status, p.category, p.tags_json, p.manual_tags_json, p.blocks_json, p.plain_text,
       p.visibility, p.access_department, p.comments_enabled, p.language_code, p.translation_group_id, p.source_page_id,
       p.source_revision, p.translation_status, p.content_revision, p.author_id, p.is_favorite, p.created_at, p.updated_at,
       u.name AS author_name, u.avatar_color AS author_color, u.avatar_kind AS author_avatar_kind,
       u.avatar_value AS author_avatar_value, u.department AS author_department,
       (SELECT source.content_revision FROM pages source WHERE source.id = p.source_page_id) AS current_source_revision,
       (SELECT COUNT(*) FROM comments c WHERE c.page_id = p.id AND c.resolved_at IS NULL) AS comment_count
FROM pages p
JOIN users u ON u.id = p.author_id
WHERE p.archived_at IS NULL
ORDER BY p.is_favorite DESC, p.updated_at DESC
SQL)->fetchAll();
    foreach ($pages as &$page) {
        $page['id'] = (int) $page['id'];
        $page['parent_id'] = $page['parent_id'] === null ? null : (int) $page['parent_id'];
        $page['sort_order'] = (int) $page['sort_order'];
        $page['is_favorite'] = (bool) $page['is_favorite'];
        $page['comments_enabled'] = (bool) $page['comments_enabled'];
        $page['comment_count'] = (int) $page['comment_count'];
        $page['source_page_id'] = $page['source_page_id'] === null ? null : (int) $page['source_page_id'];
        $page['source_revision'] = $page['source_revision'] === null ? null : (int) $page['source_revision'];
        $page['content_revision'] = (int) ($page['content_revision'] ?? 1);
        $page['translation_outdated'] = $page['source_page_id'] !== null
            && (int) ($page['source_revision'] ?? 0) < (int) ($page['current_source_revision'] ?? 1);
        unset($page['current_source_revision']);
        $page['tags'] = visiblePageTags($pdo, $page['id'], $page);
        $page['access_departments'] = pageAccessDepartments($pdo, $page['id'], $page);
        $page['access_member_ids'] = pageAccessMemberIds($pdo, $page['id']);
        unset($page['tags_json'], $page['manual_tags_json']);
    }
    unset($page);
    $pages = visiblePageTree($pdo, $user, $pages);
    $fileReferences = fileReferencesById($pages);
    foreach ($pages as &$page) {
        $page['can_edit'] = canEditPage($pdo, $user, $page);
        unset($page['blocks_json']);
    }
    unset($page);

    $trash = $pdo->query(<<<'SQL'
SELECT p.id, p.parent_id, p.title, p.icon, p.category, p.visibility, p.access_department, p.author_id, p.archived_at, p.updated_at,
       u.name AS author_name, u.avatar_color AS author_color, u.avatar_kind AS author_avatar_kind,
       u.avatar_value AS author_avatar_value, u.department AS author_department
FROM pages p
JOIN users u ON u.id = p.author_id
WHERE p.archived_at IS NOT NULL
ORDER BY p.archived_at DESC
SQL)->fetchAll();
    foreach ($trash as &$trashedPage) {
        if (!canEditPage($pdo, $user, $trashedPage)) {
            $trashedPage = null;
            continue;
        }
        $trashedPage['id'] = (int) $trashedPage['id'];
        $trashedPage['parent_id'] = $trashedPage['parent_id'] === null ? null : (int) $trashedPage['parent_id'];
        $trashedPage['author_id'] = (int) $trashedPage['author_id'];
        $trashedPage['access_departments'] = pageAccessDepartments($pdo, $trashedPage['id'], $trashedPage);
        $trashedPage['can_restore'] = true;
    }
    unset($trashedPage);
    $trash = array_values(array_filter($trash));

    $notificationRows = notificationsForUser($pdo, $user);
    $files = $pdo->query(<<<'SQL'
SELECT f.id, f.original_name, f.mime_type, f.category, f.size_bytes, f.uploaded_by, f.page_id, f.created_at,
       u.name AS uploader_name, p.title AS page_title, p.visibility AS page_visibility,
       p.author_id AS page_author_id, p.access_department AS page_access_department, p.archived_at AS page_archived_at
FROM files f
JOIN users u ON u.id = f.uploaded_by
LEFT JOIN pages p ON p.id = f.page_id
ORDER BY f.created_at DESC, f.id DESC
SQL)->fetchAll();
    foreach ($files as &$file) {
        if (!canViewFile($pdo, $user, $file)) {
            $file = null;
            continue;
        }
        $file['id'] = (int) $file['id'];
        $file['size_bytes'] = (int) $file['size_bytes'];
        $file['uploaded_by'] = (int) $file['uploaded_by'];
        $file['page_id'] = $file['page_id'] === null ? null : (int) $file['page_id'];
        $file['references'] = $fileReferences[$file['id']] ?? [];
        $file['reference_count'] = count($file['references']);
        $file['referenced'] = $file['reference_count'] > 0;
        $file['can_delete'] = canDeleteFile($pdo, $user, $file);
        unset($file['page_visibility'], $file['page_author_id'], $file['page_access_department'], $file['page_archived_at']);
    }
    unset($file);
    $files = array_values(array_filter($files));
    $members = $pdo->query(<<<'SQL'
SELECT id, name, email, avatar_color, avatar_kind, avatar_value, role, department, must_change_password, invited_at, last_login_at,
       EXISTS (
           SELECT 1 FROM audit_logs
           WHERE action = 'member_account_reactivated'
             AND subject_type = 'user'
             AND subject_id = users.id
       ) AS was_reactivated
FROM users
WHERE active = 1 AND role NOT IN ('system', 'suspended')
ORDER BY name
SQL)->fetchAll();
    foreach ($members as &$member) {
        $member['id'] = (int) $member['id'];
        $member['must_change_password'] = (bool) $member['must_change_password'];
        $member['initial_login_pending'] = $member['must_change_password'] && $member['last_login_at'] === null && !(bool) $member['was_reactivated'];
        unset($member['was_reactivated']);
    }
    unset($member);
    $activeAdministratorCount = count(array_filter(
        $members,
        static fn(array $member): bool => (string) ($member['role'] ?? '') === 'admin'
    ));
    $suspendedMembers = [];
    if ($user['role'] === 'admin') {
        $suspendedMembers = $pdo->query(<<<'SQL'
SELECT id, name, email, avatar_color, avatar_kind, avatar_value, role, department, invited_at, last_login_at
FROM users
WHERE active = 0 AND role = 'suspended'
ORDER BY name, id
SQL)->fetchAll();
        foreach ($suspendedMembers as &$suspendedMember) {
            $suspendedMember['id'] = (int) $suspendedMember['id'];
            $suspendedMember['active'] = false;
        }
        unset($suspendedMember);
    }
    $bootstrapResponse = [
        'user' => $user,
        'csrf' => $_SESSION['csrf'],
        'pages' => $pages,
        'trash' => $trash,
        'files' => $files,
        'upload_limit_mb' => uploadLimitMegabytes(),
        'profile_photo_limit_mb' => max(1, (int) floor(profilePhotoLimitBytes() / 1024 / 1024)),
        'profile_icon_choices' => profileIconChoices(),
        'notifications' => $notificationRows,
        'members' => $members,
        'departments' => organizationDepartments($pdo),
        'suspended_members' => $suspendedMembers,
        'settings' => [
            'workspace_name' => setting($pdo, 'workspace_name', 'OpenConcept'),
            'organization_name' => setting($pdo, 'organization_name', ''),
            ...wafCompatibilitySettings($pdo, true),
        ],
        'i18n' => uiI18nBundle($user),
        'capabilities' => [
            'can_create_page' => canCreatePage($user),
            'can_manage_members' => $user['role'] === 'admin',
            'can_manage_system_settings' => $user['role'] === 'admin',
            'can_edit_all' => in_array($user['role'], ['admin', 'content_admin', 'editor'], true),
            'can_comment' => $user['role'] !== 'viewer',
            'can_delete_permanently' => in_array($user['role'], ['admin', 'content_admin'], true),
            'active_administrator_count' => $activeAdministratorCount,
            'has_backup_administrator' => $activeAdministratorCount >= 2,
            'can_upload_file' => canCreatePage($user),
        ],
    ];
    if ($ownsPageSnapshot) {
        $pdo->commit();
    }
    jsonResponse($bootstrapResponse);
}

if ($action === 'page') {
    $ownsPageSnapshot = beginConsistentPageRead($pdo);
    $snapshotUser = currentUser($pdo);
    if (!$snapshotUser) {
        if ($ownsPageSnapshot && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'ログインが必要です。'], 401);
    }
    $user = $snapshotUser;
    $id = max(1, (int) ($_GET['id'] ?? 0));
    $statement = $pdo->prepare(<<<'SQL'
SELECT p.*, u.name AS author_name, u.avatar_color AS author_color, u.avatar_kind AS author_avatar_kind,
       u.avatar_value AS author_avatar_value, u.department AS author_department, e.name AS editor_name
FROM pages p
JOIN users u ON u.id = p.author_id
JOIN users e ON e.id = p.updated_by
WHERE p.id = ? AND p.archived_at IS NULL
SQL);
    $statement->execute([$id]);
    $page = $statement->fetch();
    if (!$page) {
        if ($ownsPageSnapshot && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'ページが見つかりません。'], 404);
    }
    if (!canViewPage($pdo, $user, $page)) {
        if ($ownsPageSnapshot && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'このページを閲覧する権限がありません。'], 403);
    }
    $page['id'] = (int) $page['id'];
    $page['parent_id'] = $page['parent_id'] === null ? null : (int) $page['parent_id'];
    $page['blocks'] = json_decode($page['blocks_json'], true) ?: [];
    $visibleTags = json_decode($page['tags_json'], true) ?: [];
    $storedManualTags = json_decode((string) ($page['manual_tags_json'] ?? ''), true);
    $page['manual_tags'] = uniquePageTags(is_array($storedManualTags) ? $storedManualTags : $visibleTags, 12);
    $page['generated_tags'] = activeGeneratedPageTags($pdo, $id);
    $page['tags'] = mergePageTags($page['manual_tags'], $page['generated_tags']);
    $page['comments_enabled'] = (bool) $page['comments_enabled'];
    $page['is_favorite'] = (bool) $page['is_favorite'];
    $page['source_page_id'] = $page['source_page_id'] === null ? null : (int) $page['source_page_id'];
    $page['source_revision'] = $page['source_revision'] === null ? null : (int) $page['source_revision'];
    $page['content_revision'] = (int) ($page['content_revision'] ?? 1);
    $page['can_edit'] = canEditPage($pdo, $user, $page);
    $page['access_departments'] = pageAccessDepartments($pdo, $id, $page);
    $page['access_member_ids'] = pageAccessMemberIds($pdo, $id);
    $page['mentionable_member_ids'] = [];
    $mentionableMembers = $pdo->query("SELECT id, name, email, avatar_color, avatar_kind, avatar_value, role, department FROM users WHERE active = 1 AND role NOT IN ('system', 'suspended')")->fetchAll();
    foreach ($mentionableMembers as $mentionableMember) {
        if (canViewPage($pdo, $mentionableMember, $page)) {
            $page['mentionable_member_ids'][] = (int) $mentionableMember['id'];
        }
    }
    unset($page['blocks_json'], $page['tags_json'], $page['manual_tags_json'], $page['plain_text'], $page['translation_block_map_json']);

    $comments = $pdo->prepare(<<<'SQL'
SELECT c.*, u.name AS user_name, u.avatar_color AS user_color, u.avatar_kind AS user_avatar_kind,
       u.avatar_value AS user_avatar_value
FROM comments c JOIN users u ON u.id = c.user_id
WHERE c.page_id = ? ORDER BY c.created_at ASC
SQL);
    $comments->execute([$id]);
    $page['comments'] = $comments->fetchAll();

    $revisions = $pdo->prepare(<<<'SQL'
SELECT r.id, r.page_id, r.title, r.created_at, r.created_by, u.name AS user_name, u.avatar_color AS user_color,
       u.avatar_kind AS user_avatar_kind, u.avatar_value AS user_avatar_value
FROM revisions r JOIN users u ON u.id = r.created_by
WHERE r.page_id = ? ORDER BY r.created_at DESC LIMIT 30
SQL);
    $revisions->execute([$id]);
    $page['revisions'] = $revisions->fetchAll();
    $page['translation'] = (new TranslationService($pdo, $translationProviders))->overview($id, $user);
    try {
        $ragStatus = (new RagPipeline($pdo))->pageStatus($id);
        $page['rag'] = [
            'status' => $ragStatus['job']['status'] ?? null,
            'reasoning_effort' => $ragStatus['job']['reasoning_effort'] ?? null,
            'active_chunks' => $ragStatus['active_chunks'],
            'short_summary' => $ragStatus['profile']['short_summary'] ?? null,
            'analyzed_at' => $ragStatus['profile']['analyzed_at'] ?? null,
        ];
    } catch (Throwable) {
        $page['rag'] = ['status' => 'unavailable', 'active_chunks' => 0];
    }
    if ($ownsPageSnapshot) {
        $pdo->commit();
    }
    jsonResponse(['page' => $page]);
}

if ($action === 'translation-overview' && $method === 'GET') {
    $ownsTranslationSnapshot = beginConsistentPageRead($pdo);
    try {
        $liveUser = currentUser($pdo);
        if (!is_array($liveUser)) {
            throw new RuntimeException('ログインが必要です。', 401);
        }
        $overview = (new TranslationService($pdo, $translationProviders))->overview(
            max(1, (int) ($_GET['id'] ?? 0)),
            $liveUser
        );
        $overview['available_languages'] = $i18n->availableLocales();
        if ($ownsTranslationSnapshot) {
            $pdo->commit();
        }
        jsonResponse(['translation' => $overview]);
    } catch (Throwable $exception) {
        if ($ownsTranslationSnapshot && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = in_array((int) $exception->getCode(), [401, 403, 404, 422], true)
            ? (int) $exception->getCode()
            : 500;
        jsonResponse(['error' => $status === 500 ? '翻訳情報を取得できませんでした。' : $exception->getMessage()], $status);
    }
}

if ($action === 'create-translation' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    try {
        $selectedPageIds = $body['page_ids'] ?? [];
        if (!is_array($selectedPageIds)) {
            throw new RuntimeException('翻訳対象ページの指定が正しくありません。', 422);
        }
        $result = (new TranslationService($pdo, $translationProviders))->createOrUpdateTree(
            max(1, (int) ($body['page_id'] ?? 0)),
            $selectedPageIds,
            trim((string) ($body['target_language'] ?? '')),
            trim((string) ($body['provider'] ?? '')),
            !empty($body['replace_existing']),
            $user,
            $expectedPasswordFingerprint
        );
        $translatedPageIds = array_map(
            static fn(array $page): int => (int) ($page['page_id'] ?? 0),
            is_array($result['pages'] ?? null) ? $result['pages'] : []
        );
        $affectedPublications = (new PublicPageSharing($pdo))->affectedPublicationsForPagesForUser(
            $translatedPageIds,
            $user,
            $expectedPasswordFingerprint
        );
        jsonResponse(
            ['ok' => true, 'affected_publications' => $affectedPublications] + $result,
            (int) ($result['created_count'] ?? 0) > 0 ? 201 : 200
        );
    } catch (Throwable $exception) {
        $code = (int) $exception->getCode();
        $status = in_array($code, [401, 403, 404, 409, 422, 502], true)
            ? $code
            : ($exception instanceof PDOException ? 500 : 502);
        if ($status === 401) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        }
        if ($status === 500) {
            error_log('OpenConcept translation write failed: ' . get_class($exception));
        }
        jsonResponse(['error' => $status === 500 ? '翻訳版を保存できませんでした。' : $exception->getMessage()], $status);
    }
}

if ($action === 'rag-status' && $method === 'GET') {
    $ownsRagSnapshot = beginConsistentPageRead($pdo);
    try {
        $liveRagUser = currentUser($pdo);
        if (!is_array($liveRagUser) || !in_array((string) $liveRagUser['role'], ['admin', 'content_admin'], true)) {
            if ($ownsRagSnapshot && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['error' => 'You do not have permission to view RAG status.'], 403);
        }
        $id = max(0, (int) ($_GET['id'] ?? 0));
        if ($id > 0) {
            $ragPayload = ['rag' => (new RagPipeline($pdo))->pageStatus($id)];
        } else {
            $counts = [];
            $currentJobs = $pdo->prepare(<<<'SQL'
SELECT j.status, COUNT(*) AS total
FROM rag_jobs j
JOIN rag_source_documents d
  ON d.page_id = j.page_id
 AND d.source_hash = j.source_hash
 AND d.is_current = 1
 AND d.source_schema_version = ?
WHERE j.prompt_version = ?
GROUP BY j.status
SQL);
            $currentJobs->execute([RagPipeline::SOURCE_SCHEMA_VERSION, RagPipeline::PROMPT_VERSION]);
            foreach ($currentJobs->fetchAll() as $row) {
                $counts[(string) $row['status']] = (int) $row['total'];
            }
            $currentSources = $pdo->prepare(
                'SELECT COUNT(*) FROM rag_source_documents WHERE is_current = 1 AND source_schema_version = ?'
            );
            $currentSources->execute([RagPipeline::SOURCE_SCHEMA_VERSION]);
            $activeChunks = $pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM rag_chunks c
JOIN rag_source_documents d
  ON d.page_id = c.page_id
 AND d.source_hash = c.source_hash
 AND d.is_current = 1
 AND d.source_schema_version = ?
WHERE c.is_active = 1 AND c.prompt_version = ?
SQL);
            $activeChunks->execute([RagPipeline::SOURCE_SCHEMA_VERSION, RagPipeline::PROMPT_VERSION]);
            $ragPayload = [
                'rag' => [
                    'jobs' => $counts,
                    'source_schema_version' => RagPipeline::SOURCE_SCHEMA_VERSION,
                    'prompt_version' => RagPipeline::PROMPT_VERSION,
                    'current_sources' => (int) $currentSources->fetchColumn(),
                    'active_chunks' => (int) $activeChunks->fetchColumn(),
                ],
            ];
        }
        if ($ownsRagSnapshot) {
            $pdo->commit();
        }
        jsonResponse($ragPayload);
    } catch (Throwable $exception) {
        if ($ownsRagSnapshot && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

if ($action === 'rag-reprocess' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $id = (int) ($body['id'] ?? 0);
    if ($id < 1) {
        jsonResponse(['error' => '再処理するページを指定してください。'], 422);
    }
    try {
        beginPageWriteTransaction($pdo);
        $liveRagUser = lockedAuthenticatedApplicationUserForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        if (!is_array($liveRagUser)) {
            throw new RuntimeException('Your session is no longer valid.', 401);
        }
        if (!in_array((string) $liveRagUser['role'], ['admin', 'content_admin'], true)) {
            throw new RuntimeException('You do not have permission to reprocess RAG data.', 403);
        }
        if (!is_array(lockedPageForUpdate($pdo, $id))) {
            throw new RuntimeException('The requested page was not found.', 404);
        }
        $pdo->commit();
        jsonResponse(['ok' => true, 'rag' => queueRagPage($pdo, $id, true)]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $status = in_array($exception->getCode(), [401, 403, 404], true) ? $exception->getCode() : 500;
        if ($status === 500) {
            error_log('OpenConcept RAG reprocess authorization error: ' . $exception->getMessage());
        }
        $message = $status === 500
            ? 'RAG reprocessing could not be started.'
            : $exception->getMessage();
        jsonResponse(['error' => $message], $status);
    }
}

if ($action === 'create-page' && $method === 'POST') {
    requireCsrf();
    if (!canCreatePage($user)) {
        jsonResponse(['error' => 'ページを作成する権限がありません。'], 403);
    }
    $body = bodyJson();
    $parentId = isset($body['parent_id']) ? (int) $body['parent_id'] : null;
    $languageCode = trim((string) ($body['language_code'] ?? 'und'));
    if ($languageCode !== 'und' && !I18n::isValidLocale($languageCode)) {
        jsonResponse(['error' => 'ページの言語コードが正しくありません。'], 422);
    }
    beginPageWriteTransaction($pdo);
    $abortCreate = static function (array $payload, int $status) use ($pdo): never {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse($payload, $status);
    };
    try {
        $liveUser = lockedAuthenticatedApplicationUserForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        if ($liveUser === null) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
            $abortCreate(['error' => 'ログイン状態が変更されました。もう一度ログインしてください。'], 401);
        }
        $user = $liveUser;
        if (!canCreatePage($user)) {
            $abortCreate(['error' => 'ページを作成する権限がありません。'], 403);
        }
        $parentPage = null;
        if ($parentId) {
            $lockedHierarchy = lockedPageHierarchiesForUpdate($pdo, [$parentId]);
            if ($lockedHierarchy['reason'] !== null) {
                $statusCode = $lockedHierarchy['reason'] === 'missing'
                    && (int) ($lockedHierarchy['page_id'] ?? 0) === $parentId
                    ? 403
                    : 409;
                $abortCreate([
                    'error' => $statusCode === 403
                        ? '親ページを指定できません。'
                        : '親ページの階層が更新されました。もう一度お試しください。',
                    'code' => $statusCode === 409 ? 'page_hierarchy_conflict' : 'parent_page_unavailable',
                ], $statusCode);
            }
            $parentPage = $lockedHierarchy['rows'][$parentId] ?? null;
            if (!$parentPage || !canEditPage($pdo, $user, $parentPage)) {
                $abortCreate(['error' => '親ページを指定できません。'], 403);
            }
        }

        $orderStatement = $parentId
            ? $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM pages WHERE parent_id = ? AND archived_at IS NULL')
            : $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM pages WHERE parent_id IS NULL AND archived_at IS NULL');
        $orderStatement->execute($parentId ? [$parentId] : []);
        $sortOrder = (int) $orderStatement->fetchColumn();
        $blocks = [['id' => 'b-' . bin2hex(random_bytes(5)), 'type' => 'paragraph', 'content' => '']];
        $parentIsPrivate = $parentPage && pageHierarchyForcesPrivate($pdo, (int) $parentPage['id']);
        $visibility = $parentIsPrivate ? 'private' : ($parentPage['visibility'] ?? 'company');
        $status = $parentIsPrivate ? 'private' : 'draft';
        $accessDepartments = $parentPage
            ? pageAccessDepartments($pdo, (int) $parentPage['id'], $parentPage)
            : normalizePageAccessDepartments([(string) ($user['department'] ?? '')]);
        $accessDepartment = legacyPageAccessDepartment($accessDepartments, (string) ($user['department'] ?? ''));
        $statement = $pdo->prepare('INSERT INTO pages (parent_id, sort_order, title, icon, cover, status, category, tags_json, manual_tags_json, blocks_json, plain_text, visibility, access_department, language_code, author_id, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$parentId, $sortOrder, '無題', '📄', 'none', $status, 'ナレッジ', '[]', '[]', json_encode($blocks, JSON_UNESCAPED_UNICODE), '', $visibility, $accessDepartment, $languageCode, (int) $user['id'], (int) $user['id']]);
        $id = (int) $pdo->lastInsertId();
        replacePageAccessDepartments($pdo, $id, $accessDepartments, (int) $user['id']);
        if ($parentPage && $visibility === 'group') {
            $copyAccess = $pdo->prepare('INSERT INTO page_access_members (page_id, user_id, granted_by) SELECT ?, user_id, ? FROM page_access_members WHERE page_id = ?');
            $copyAccess->execute([$id, (int) $user['id'], $parentId]);
        }
        audit($pdo, (int) $user['id'], 'created', 'page', $id);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    Hooks::action('article_created', ['id' => $id, 'parent_id' => $parentId]);
    jsonResponse(['ok' => true, 'id' => $id], 201);
}

if ($action === 'move-page' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $id = max(1, (int) ($body['id'] ?? 0));
    $targetId = max(0, (int) ($body['target_id'] ?? 0));
    $position = in_array($body['position'] ?? '', ['before', 'after', 'inside', 'root-end'], true) ? $body['position'] : '';
    if ($position === '' || ($position !== 'root-end' && $targetId < 1) || $id === $targetId) {
        jsonResponse(['error' => '移動先を確認してください。'], 422);
    }

    $loadSiblingIds = static function (?int $parentId) use ($pdo): array {
        if ($parentId === null) {
            return array_map('intval', $pdo->query("SELECT id FROM pages WHERE parent_id IS NULL AND archived_at IS NULL ORDER BY sort_order, title, id")->fetchAll(PDO::FETCH_COLUMN));
        }
        $statement = $pdo->prepare('SELECT id FROM pages WHERE parent_id = ? AND archived_at IS NULL ORDER BY sort_order, title, id');
        $statement->execute([$parentId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    };
    $normalizeOrder = static function (array $ids) use ($pdo): void {
        $statement = $pdo->prepare('UPDATE pages SET sort_order = ? WHERE id = ?');
        foreach (array_values($ids) as $index => $pageId) {
            $statement->execute([($index + 1) * 10, $pageId]);
        }
    };
    $inheritedPageIds = [];
    $inheritedVisibility = null;
    $inheritedStatus = null;
    beginPageWriteTransaction($pdo);
    $abortMove = static function (array $payload, int $status) use ($pdo): never {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse($payload, $status);
    };
    try {
        $liveUser = lockedAuthenticatedApplicationUserForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        if ($liveUser === null) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
            $abortMove(['error' => 'ログイン状態が変更されました。もう一度ログインしてください。'], 401);
        }
        $user = $liveUser;
        $scopePageIds = [$id];
        if ($position !== 'root-end') {
            $scopePageIds[] = $targetId;
        }
        $lockedHierarchy = lockedPageHierarchiesForUpdate($pdo, $scopePageIds);
        if ($lockedHierarchy['reason'] !== null) {
            $missingId = (int) ($lockedHierarchy['page_id'] ?? 0);
            if ($lockedHierarchy['reason'] === 'missing' && $missingId === $id) {
                $abortMove(['error' => 'このページを移動する権限がありません。'], 403);
            }
            if ($lockedHierarchy['reason'] === 'missing' && $missingId === $targetId) {
                $abortMove(['error' => '移動先のページを編集できません。'], 403);
            }
            $abortMove([
                'error' => 'ページの階層が更新されました。もう一度お試しください。',
                'code' => 'page_hierarchy_conflict',
            ], 409);
        }
        $lockedRows = $lockedHierarchy['rows'];
        $movingPage = $lockedRows[$id] ?? null;
        if (!$movingPage || !canEditPage($pdo, $user, $movingPage)) {
            $abortMove(['error' => 'このページを移動する権限がありません。'], 403);
        }

        $movingSubtree = lockedPageSubtreeForUpdate($pdo, $id);
        if ($movingSubtree['reason'] !== null) {
            $abortMove([
                'error' => 'ページ階層が別の操作で更新されました。もう一度お試しください。',
                'code' => 'page_hierarchy_conflict',
            ], 409);
        }
        $publicationRoots = (new PublicPageSharing($pdo))->enabledPublicationRootIdsForPages($movingSubtree['ids']);

        $targetPage = null;
        if ($position !== 'root-end') {
            $targetPage = $lockedRows[$targetId] ?? null;
            if (!$targetPage || !canEditPage($pdo, $user, $targetPage)) {
                $abortMove(['error' => '移動先のページを編集できません。'], 403);
            }
        }
        $newParentId = $position === 'inside'
            ? $targetId
            : ($position === 'root-end' ? null : ($targetPage['parent_id'] === null ? null : (int) $targetPage['parent_id']));
        $inheritanceParent = $newParentId === null ? null : ($lockedRows[$newParentId] ?? null);
        if ($newParentId !== null && !$inheritanceParent) {
            $abortMove([
                'error' => '移動先の階層が更新されました。もう一度お試しください。',
                'code' => 'page_hierarchy_conflict',
            ], 409);
        }

        $cursor = $newParentId;
        $visited = [];
        while ($cursor !== null) {
            if ($cursor === $id || isset($visited[$cursor])) {
                $abortMove(['error' => '自分自身または子ページの中には移動できません。'], 422);
            }
            $visited[$cursor] = true;
            $ancestor = $lockedRows[$cursor] ?? null;
            if (!$ancestor) {
                $abortMove([
                    'error' => '移動先の階層が更新されました。もう一度お試しください。',
                    'code' => 'page_hierarchy_conflict',
                ], 409);
            }
            $cursor = $ancestor['parent_id'] === null ? null : (int) $ancestor['parent_id'];
        }

        if ($publicationRoots !== []) {
            $movingPageSet = array_fill_keys(array_map('intval', $movingSubtree['ids']), true);
            $destinationForcesPrivate = $newParentId !== null
                && pageHierarchyForcesPrivate($pdo, $newParentId);
            $unsafePublicationRoots = [];
            foreach ($publicationRoots as $publicationRootId) {
                $publicationRootId = (int) $publicationRootId;
                if ($destinationForcesPrivate
                    || (!isset($movingPageSet[$publicationRootId]) && !isset($visited[$publicationRootId]))) {
                    $unsafePublicationRoots[] = $publicationRootId;
                }
            }
            if ($unsafePublicationRoots !== []) {
                $abortMove([
                    'error' => '移動すると静的Webサイトの公開範囲または公開状態から外れます。先に公開対象から外すか、Web公開を停止してください。',
                    'code' => 'active_publication_requires_stop',
                    'publication_root_ids' => $unsafePublicationRoots,
                ], 409);
            }
        }

        $oldParentId = $movingPage['parent_id'] === null ? null : (int) $movingPage['parent_id'];
        $destinationIds = array_values(array_filter($loadSiblingIds($newParentId), static fn(int $pageId): bool => $pageId !== $id));
        if ($position === 'root-end' || $position === 'inside') {
            $insertAt = count($destinationIds);
        } else {
            $targetIndex = array_search($targetId, $destinationIds, true);
            if ($targetIndex === false) {
                $abortMove(['error' => '移動先の並び順を確認できません。'], 422);
            }
            $insertAt = $targetIndex + ($position === 'after' ? 1 : 0);
        }
        array_splice($destinationIds, $insertAt, 0, [$id]);

        $inheritedAccessDepartments = $inheritanceParent
            ? pageAccessDepartments($pdo, (int) $inheritanceParent['id'], $inheritanceParent)
            : [];
        $updateParent = $pdo->prepare('UPDATE pages SET parent_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $updateParent->execute([$newParentId, $id]);
        if ($oldParentId !== $newParentId) {
            $oldIds = array_values(array_filter($loadSiblingIds($oldParentId), static fn(int $pageId): bool => $pageId !== $id));
            $normalizeOrder($oldIds);
        }
        $normalizeOrder($destinationIds);
        if ($inheritanceParent) {
            $forcePrivate = pageHierarchyForcesPrivate($pdo, (int) $inheritanceParent['id'])
                || ($movingPage['status'] ?? '') === 'private'
                || ($movingPage['visibility'] ?? '') === 'private';
            $inheritedVisibility = $forcePrivate ? 'private' : (string) $inheritanceParent['visibility'];
            $inheritedStatus = $forcePrivate ? 'private' : (string) $movingPage['status'];
            $inheritedPageIds = applyPageAccessInheritance(
                $pdo,
                $id,
                $inheritedVisibility,
                $inheritedAccessDepartments,
                $inheritedVisibility === 'group' ? pageAccessMemberIds($pdo, $newParentId) : [],
                $forcePrivate,
                (int) $user['id'],
                true
            );
        }
        audit($pdo, (int) $user['id'], 'moved', 'page', $id, ['parent_id' => $newParentId, 'position' => $position, 'target_id' => $targetId ?: null]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    foreach ($inheritedPageIds as $inheritedPageId) {
        queueRagPage($pdo, $inheritedPageId);
    }
    $movedStateStatement = $pdo->prepare('SELECT status, visibility, access_department FROM pages WHERE id = ?');
    $movedStateStatement->execute([$id]);
    $movedState = $movedStateStatement->fetch() ?: [];
    $movedAccessDepartments = pageAccessDepartments($pdo, $id, array_merge($movingPage, $movedState));
    $affectedPublicationsByRoot = [];
    foreach ($publicationRoots as $publicationRootId) {
        foreach ((new PublicPageSharing($pdo))->affectedPublicationsForUser(
            (int) $publicationRootId,
            $user,
            $expectedPasswordFingerprint
        ) as $publication) {
            $affectedPublicationsByRoot[(int) $publication['root_page_id']] = $publication;
        }
    }
    jsonResponse([
        'ok' => true,
        'parent_id' => $newParentId,
        'sort_order' => ($insertAt + 1) * 10,
        'visibility' => (string) ($movedState['visibility'] ?? $movingPage['visibility']),
        'status' => (string) ($movedState['status'] ?? $movingPage['status']),
        'access_department' => (string) ($movedState['access_department'] ?? $movingPage['access_department'] ?? ''),
        'access_departments' => $movedAccessDepartments,
        'access_member_ids' => (string) ($movedState['visibility'] ?? $movingPage['visibility']) === 'group'
            ? pageAccessMemberIds($pdo, $id)
            : [],
        'inherited_pages' => count($inheritedPageIds),
        'affected_publications' => array_values($affectedPublicationsByRoot),
    ]);
}

if ($action === 'save-page' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    try {
        PageSaveInput::validate($body, $GLOBALS['openconceptJsonNativeBody'] ?? null);
    } catch (PageSaveInputException $exception) {
        jsonResponse(['error' => $exception->getMessage(), 'code' => $exception->failureCode], 422);
    }
    unset($GLOBALS['openconceptJsonNativeBody']);
    $id = (int) ($body['id'] ?? 0);
    $blocks = sanitizeBlocks($body['blocks'] ?? []);
    $plain = blocksPlainText($blocks);
    $title = cleanText((string) ($body['title'] ?? '無題'), 240) ?: '無題';
    $manualTags = uniquePageTags((array) (array_key_exists('manual_tags', $body) ? $body['manual_tags'] : ($body['tags'] ?? [])), 12);
    // Generated tags are derived from a particular RAG source generation.
    // Never carry them through a page save into a different content/ACL
    // generation; the current-source-aware reader adds them back when safe.
    $generatedTags = [];
    $tags = $manualTags;

    $allowedStatuses = ['draft', 'review', 'published', 'private', 'archived'];
    $allowedVisibilities = ['company', 'department', 'group', 'private'];
    $parseExpectedDepartments = static function (mixed $value): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            jsonResponse(['error' => '共有設定の確認情報にある対象部署を確認してください。', 'code' => 'invalid_expected_access_state'], 422);
        }
        foreach ($value as $department) {
            if (!is_string($department) || $department === '' || cleanText($department, 120) !== $department) {
                jsonResponse(['error' => '共有設定の確認情報にある対象部署を確認してください。', 'code' => 'invalid_expected_access_state'], 422);
            }
        }
        $normalized = normalizePageAccessDepartments($value);
        if (count($normalized) !== count($value)) {
            jsonResponse(['error' => '共有設定の確認情報にある対象部署を確認してください。', 'code' => 'invalid_expected_access_state'], 422);
        }
        return $normalized;
    };
    $parseExpectedMembers = static function (mixed $value): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > 200) {
            jsonResponse(['error' => '共有設定の確認情報にある対象メンバーを確認してください。', 'code' => 'invalid_expected_access_state'], 422);
        }
        foreach ($value as $memberId) {
            if (!is_int($memberId) || $memberId < 1) {
                jsonResponse(['error' => '共有設定の確認情報にある対象メンバーを確認してください。', 'code' => 'invalid_expected_access_state'], 422);
            }
        }
        $normalized = canonicalPageAccessState('', '', [], $value)['access_member_ids'];
        if (count($normalized) !== count($value)) {
            jsonResponse(['error' => '共有設定の確認情報にある対象メンバーを確認してください。', 'code' => 'invalid_expected_access_state'], 422);
        }
        return $normalized;
    };

    $expectedAccessState = null;
    if (array_key_exists('expected_access_state', $body)) {
        $expected = $body['expected_access_state'];
        if (!is_array($expected) || array_is_list($expected)
            || !array_key_exists('status', $expected)
            || !array_key_exists('visibility', $expected)
            || !array_key_exists('access_departments', $expected)
            || !array_key_exists('access_member_ids', $expected)
            || !is_string($expected['status'])
            || !in_array($expected['status'], $allowedStatuses, true)
            || !is_string($expected['visibility'])
            || !in_array($expected['visibility'], $allowedVisibilities, true)) {
            jsonResponse(['error' => '共有設定の確認情報を確認してください。', 'code' => 'invalid_expected_access_state'], 422);
        }
        $expectedAccessState = canonicalPageAccessState(
            $expected['status'],
            $expected['visibility'],
            $parseExpectedDepartments($expected['access_departments']),
            $parseExpectedMembers($expected['access_member_ids'])
        );
    }

    $pluralDepartmentsProvided = array_key_exists('access_departments', $body);
    $requestedPluralDepartments = null;
    if ($pluralDepartmentsProvided) {
        if (!is_array($body['access_departments']) || !array_is_list($body['access_departments'])
            || count($body['access_departments']) > 50) {
            jsonResponse(['error' => '対象部署は50件以内で選択してください。'], 422);
        }
        foreach ($body['access_departments'] as $requestedDepartment) {
            if (!is_string($requestedDepartment) || cleanText($requestedDepartment, 120) === '') {
                jsonResponse(['error' => '対象部署を確認してください。'], 422);
            }
        }
        $requestedPluralDepartments = normalizePageAccessDepartments($body['access_departments']);
    }

    $requestedMembersProvided = array_key_exists('access_member_ids', $body);
    if ($requestedMembersProvided && (!is_array($body['access_member_ids']) || count($body['access_member_ids']) > 200)) {
        jsonResponse(['error' => '対象メンバーは200件以内で選択してください。'], 422);
    }
    $requestedMemberIds = $requestedMembersProvided
        ? canonicalPageAccessState('', '', [], (array) $body['access_member_ids'])['access_member_ids']
        : null;

    $inheritedPageIds = [];
    $affectedPublications = [];
    beginPageWriteTransaction($pdo);
    $abortSave = static function (array $payload, int $status) use ($pdo): never {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse($payload, $status);
    };
    try {
        $liveUser = lockedAuthenticatedApplicationUserForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        if ($liveUser === null) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
            $abortSave(['error' => 'ログイン状態が変更されました。もう一度ログインしてください。'], 401);
        }
        $user = $liveUser;
        $lockedHierarchy = lockedPageHierarchiesForUpdate($pdo, [$id]);
        if ($lockedHierarchy['reason'] !== null) {
            if ($lockedHierarchy['reason'] === 'missing'
                && (int) ($lockedHierarchy['page_id'] ?? 0) === $id) {
                $abortSave(['error' => 'ページが見つかりません。'], 404);
            }
            $abortSave([
                'error' => 'ページの階層が更新されました。もう一度お試しください。',
                'code' => 'page_hierarchy_conflict',
            ], 409);
        }
        $existing = $lockedHierarchy['rows'][$id] ?? null;
        if (!$existing) {
            $abortSave(['error' => 'ページが見つかりません。'], 404);
        }
        if (!canEditPage($pdo, $user, $existing)) {
            $abortSave(['error' => 'このページを編集する権限がありません。'], 403);
        }
        $languageCode = array_key_exists('language_code', $body)
            ? trim((string) $body['language_code'])
            : (string) ($existing['language_code'] ?? 'und');
        if ($languageCode !== 'und' && !I18n::isValidLocale($languageCode)) {
            $abortSave(['error' => 'ページの言語コードが正しくありません。'], 422);
        }
        if ((int) ($existing['source_page_id'] ?? 0) > 0
            && $languageCode !== (string) ($existing['language_code'] ?? 'und')) {
            $abortSave(['error' => '翻訳版の言語は変更できません。別の言語版を作成してください。'], 422);
        }

        $normalizedDepartmentRows = normalizedPageAccessDepartmentRows($pdo, $id);
        $normalizedDepartmentAclExists = $normalizedDepartmentRows !== [];
        $existingAccessDepartments = $normalizedDepartmentAclExists
            ? $normalizedDepartmentRows
            : pageAccessDepartments($pdo, $id, $existing);
        $existingAccessMemberIds = pageAccessMemberIds($pdo, $id);
        $currentAccessState = canonicalPageAccessState(
            (string) $existing['status'],
            (string) $existing['visibility'],
            $existingAccessDepartments,
            $existingAccessMemberIds
        );

        $requestedStatus = array_key_exists('status', $body)
            ? (in_array($body['status'], $allowedStatuses, true) ? (string) $body['status'] : 'draft')
            : (string) $existing['status'];
        $requestedVisibility = array_key_exists('visibility', $body)
            ? (in_array($body['visibility'], $allowedVisibilities, true) ? (string) $body['visibility'] : 'company')
            : (string) $existing['visibility'];

        if ($pluralDepartmentsProvided) {
            if ($requestedPluralDepartments === []) {
                if ($requestedVisibility === 'department') {
                    $abortSave(['error' => '対象部署を1つ以上選択してください。'], 422);
                }
                // An empty array outside department visibility means that the
                // client did not intend to clear the latent department choice.
                $requestedAccessDepartments = $expectedAccessState['access_departments'] ?? $existingAccessDepartments;
            } else {
                $requestedAccessDepartments = $requestedPluralDepartments;
            }
        } elseif ($requestedVisibility === 'department' && array_key_exists('access_department', $body)) {
            $requestedAccessDepartment = cleanText((string) $body['access_department'], 120);
            if ($requestedAccessDepartment === '') {
                $abortSave(['error' => '対象部署を選択してください。'], 422);
            }
            $requestedAccessDepartments = pageAccessDepartmentsFromLegacyRequest(
                $existingAccessDepartments,
                $requestedAccessDepartment
            );
        } else {
            $requestedAccessDepartments = $existingAccessDepartments;
        }

        $requestedAccessMemberIds = $requestedVisibility === 'group'
            ? ($requestedMembersProvided
                ? (array) $requestedMemberIds
                : ((string) $existing['visibility'] === 'group' ? $existingAccessMemberIds : []))
            : [];
        $requestedAccessState = canonicalPageAccessState(
            $requestedStatus,
            $requestedVisibility,
            $requestedAccessDepartments,
            $requestedAccessMemberIds
        );
        $accessResolution = resolvePageAccessStateUpdate(
            $currentAccessState,
            $requestedAccessState,
            $expectedAccessState
        );
        if ($accessResolution['conflict']) {
            $abortSave([
                'error' => '共有設定が別の画面で更新されています。最新の設定を確認してください。',
                'code' => 'page_access_conflict',
                'current_access_state' => $currentAccessState,
            ], 409);
        }

        $resolvedAccessState = $accessResolution['state'];
        $status = (string) $resolvedAccessState['status'];
        $visibility = (string) $resolvedAccessState['visibility'];
        $accessDepartments = (array) $resolvedAccessState['access_departments'];
        $accessMemberIds = (array) $resolvedAccessState['access_member_ids'];

        $allowedAccessDepartmentValues = array_merge(organizationDepartments($pdo), $existingAccessDepartments);
        $allowedAccessDepartments = normalizePageAccessDepartments(
            $allowedAccessDepartmentValues,
            max(50, count($allowedAccessDepartmentValues))
        );
        foreach ($accessDepartments as $accessDepartmentCandidate) {
            if (!in_array($accessDepartmentCandidate, $allowedAccessDepartments, true)) {
                $abortSave(['error' => '現在登録されている部署から対象部署を選択してください。'], 422);
            }
        }
        if ($visibility === 'department' && $accessDepartments === []) {
            $abortSave(['error' => '対象部署を1つ以上選択してください。'], 422);
        }

        if ($accessMemberIds) {
            $memberPlaceholders = sqlPlaceholders($accessMemberIds);
            $memberStatement = $pdo->prepare("SELECT id FROM users WHERE active = 1 AND id IN ({$memberPlaceholders})");
            $memberStatement->execute($accessMemberIds);
            $allowedAccessMemberIds = array_values(array_unique(array_merge(
                array_map('intval', $memberStatement->fetchAll(PDO::FETCH_COLUMN)),
                $existingAccessMemberIds
            )));
            foreach ($accessMemberIds as $accessMemberId) {
                if (!in_array((int) $accessMemberId, $allowedAccessMemberIds, true)) {
                    $abortSave(['error' => '現在登録されているメンバーから対象者を選択してください。'], 422);
                }
            }
        }

        $ancestorForcesPrivate = $existing['parent_id'] !== null
            && pageHierarchyForcesPrivate($pdo, (int) $existing['parent_id']);
        $publicRequestBlocked = $ancestorForcesPrivate && ($status !== 'private' || $visibility !== 'private');
        if ($existing['status'] === 'private' && $existing['visibility'] === 'private') {
            if ($status !== 'private' && $visibility === 'private') {
                $visibility = 'company';
            } elseif ($visibility !== 'private' && $status === 'private') {
                $status = 'draft';
            }
        }
        if ($status === 'private' || $visibility === 'private') {
            $status = 'private';
            $visibility = 'private';
        }
        if ($ancestorForcesPrivate) {
            $status = 'private';
            $visibility = 'private';
        }
        if ($visibility === 'group' && !canManageAllContent($user) && (int) $existing['author_id'] !== (int) $user['id']) {
            $accessMemberIds[] = (int) $user['id'];
            $accessMemberIds = array_values(array_unique(array_map('intval', $accessMemberIds)));
            sort($accessMemberIds, SORT_NUMERIC);
        }
        if ($visibility !== 'group') {
            $accessMemberIds = [];
        }
        if (((string) ($existing['status'] ?? '') === 'published' && $status !== 'published')
            || $status === 'private') {
            $publicationScopeIds = [$id];
            if ($status === 'private') {
                $publicationSubtree = lockedPageSubtreeForUpdate($pdo, $id);
                if ($publicationSubtree['reason'] !== null) {
                    $abortSave([
                        'error' => 'ページ階層が別の操作で更新されました。もう一度お試しください。',
                        'code' => 'page_hierarchy_conflict',
                    ], 409);
                }
                $publicationScopeIds = $publicationSubtree['ids'];
            }
            $publicationRoots = (new PublicPageSharing($pdo))->enabledPublicationRootIdsForPages($publicationScopeIds);
            if ($publicationRoots !== []) {
                $abortSave([
                    'error' => 'このページは静的Webサイトで公開中です。先に公開対象から外すか、Web公開を停止してください。',
                    'code' => 'active_publication_requires_published_page',
                    'publication_root_ids' => $publicationRoots,
                ], 409);
            }
        }
        $accessDepartment = legacyPageAccessDepartment(
            $accessDepartments,
            (string) ($existing['access_department'] ?? '')
        );

        sort($existingAccessMemberIds, SORT_NUMERIC);
        $normalizedAccessMemberIds = $accessMemberIds;
        sort($normalizedAccessMemberIds, SORT_NUMERIC);
        $accessChanged = $status !== $existing['status']
            || $visibility !== $existing['visibility']
            || $accessDepartments !== $existingAccessDepartments
            || $accessDepartment !== (string) ($existing['access_department'] ?? '')
            || $normalizedAccessMemberIds !== $existingAccessMemberIds;
        $meta = [
            'status' => $existing['status'],
            'category' => $existing['category'],
            'tags' => json_decode($existing['tags_json'], true),
            'manual_tags' => json_decode((string) ($existing['manual_tags_json'] ?? ''), true),
            'visibility' => $existing['visibility'],
            'access_department' => (string) ($existing['access_department'] ?? ''),
            'access_departments' => $existingAccessDepartments,
            'access_member_ids' => $existingAccessMemberIds,
            'language_code' => (string) ($existing['language_code'] ?? 'und'),
            'content_revision' => (int) ($existing['content_revision'] ?? 1),
            'translation_status' => (string) ($existing['translation_status'] ?? 'original'),
            'source_revision' => $existing['source_revision'] === null ? null : (int) $existing['source_revision'],
        ];
        $blocksJson = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $translatedContentChanged = $title !== (string) $existing['title']
            || $blocksJson !== (string) $existing['blocks_json'];
        $contentChanged = $translatedContentChanged
            || $languageCode !== (string) ($existing['language_code'] ?? 'und');
        $meaningfulUpdate = $contentChanged
            || (cleanText((string) ($body['icon'] ?? '📄'), 8) ?: '📄') !== (string) $existing['icon']
            || (in_array($body['cover'] ?? '', ['none', 'mint', 'blue', 'sand', 'coral', 'lavender', 'night'], true) ? $body['cover'] : 'none') !== (string) $existing['cover']
            || $status !== (string) $existing['status']
            || $visibility !== (string) $existing['visibility']
            || $accessChanged
            || cleanText((string) ($body['category'] ?? 'ナレッジ'), 80) !== (string) $existing['category']
            || json_encode($manualTags, JSON_UNESCAPED_UNICODE) !== (string) ($existing['manual_tags_json'] ?? '')
            || (!empty($body['comments_enabled']) ? 1 : 0) !== (int) $existing['comments_enabled'];

        $revision = $pdo->prepare('INSERT INTO revisions (page_id, title, icon, blocks_json, meta_json, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $revision->execute([$id, $existing['title'], $existing['icon'], $existing['blocks_json'], json_encode($meta, JSON_UNESCAPED_UNICODE), (int) $user['id']]);
        $statement = $pdo->prepare(<<<'SQL'
UPDATE pages SET title = ?, icon = ?, cover = ?, status = ?, category = ?, tags_json = ?, manual_tags_json = ?, blocks_json = ?,
plain_text = ?, visibility = ?, access_department = ?, comments_enabled = ?, language_code = ?,
content_revision = content_revision + ?,
translation_status = CASE WHEN source_page_id IS NOT NULL AND ? = 1 THEN 'human_edited' ELSE translation_status END,
updated_by = ?, updated_at = CURRENT_TIMESTAMP,
published_at = CASE WHEN ? = 'private' THEN NULL WHEN ? = 'published' AND published_at IS NULL THEN CURRENT_TIMESTAMP ELSE published_at END
WHERE id = ?
SQL);
        $statement->execute([
            $title,
            cleanText((string) ($body['icon'] ?? '📄'), 8) ?: '📄',
            in_array($body['cover'] ?? '', ['none', 'mint', 'blue', 'sand', 'coral', 'lavender', 'night'], true) ? $body['cover'] : 'none',
            $status,
            cleanText((string) ($body['category'] ?? 'ナレッジ'), 80),
            json_encode($tags, JSON_UNESCAPED_UNICODE),
            json_encode($manualTags, JSON_UNESCAPED_UNICODE),
            $blocksJson,
            utf8Slice($plain, 100000),
            $visibility,
            $accessDepartment,
            !empty($body['comments_enabled']) ? 1 : 0,
            $languageCode,
            $contentChanged ? 1 : 0,
            $translatedContentChanged ? 1 : 0,
            (int) $user['id'],
            $status,
            $status,
            $id,
        ]);
        replacePageAccessDepartments($pdo, $id, $accessDepartments, (int) $user['id']);
        $pdo->prepare('DELETE FROM page_access_members WHERE page_id = ?')->execute([$id]);
        if ($visibility === 'group' && $accessMemberIds) {
            $grant = $pdo->prepare('INSERT INTO page_access_members (page_id, user_id, granted_by) VALUES (?, ?, ?)');
            foreach ($accessMemberIds as $memberId) {
                $grant->execute([$id, $memberId, (int) $user['id']]);
            }
        }
        if ($accessChanged) {
            $inheritedPageIds = applyPageAccessInheritance(
                $pdo,
                $id,
                $visibility,
                $accessDepartments,
                $accessMemberIds,
                $status === 'private',
                (int) $user['id']
            );
        }
        invalidateRagPageGenerations($pdo, [$id]);
        if ($meaningfulUpdate && (int) $existing['author_id'] !== (int) $user['id']) {
            $actorName = cleanText((string) $user['name'], 120) ?: 'メンバー';
            createOrRefreshNotification(
                $pdo,
                (int) $existing['author_id'],
                'update',
                "{$actorName}さんが「{$title}」を更新しました",
                $id
            );
        }
        audit($pdo, (int) $user['id'], 'updated', 'page', $id, ['title' => $title]);
        $affectedPublications = $meaningfulUpdate
            ? $affectedPublicationsForPageIds([$id, ...$inheritedPageIds])
            : [];
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    Hooks::action('article_updated', ['id' => $id], $existing);
    if ($status === 'published' && $existing['status'] !== 'published') {
        Hooks::action('article_published', ['id' => $id]);
    }
    $rag = queueRagPage($pdo, $id);
    foreach ($inheritedPageIds as $inheritedPageId) {
        queueRagPage($pdo, $inheritedPageId);
    }
    $generatedTags = activeGeneratedPageTags($pdo, $id);
    $tags = mergePageTags($manualTags, $generatedTags);
    $updatedAtStatement = $pdo->prepare('SELECT updated_at, content_revision, translation_status FROM pages WHERE id = ?');
    $updatedAtStatement->execute([$id]);
    $persistedPageState = $updatedAtStatement->fetch() ?: [];
    $persistedUpdatedAt = (string) ($persistedPageState['updated_at'] ?? date('Y-m-d H:i:s'));
    jsonResponse([
        'ok' => true,
        'updated_at' => $persistedUpdatedAt,
        'language_code' => $languageCode,
        'content_revision' => (int) ($persistedPageState['content_revision'] ?? 1),
        'translation_status' => (string) ($persistedPageState['translation_status'] ?? 'original'),
        'status' => $status,
        'visibility' => $visibility,
        'access_department' => $accessDepartment,
        'access_departments' => $accessDepartments,
        'access_state' => canonicalPageAccessState($status, $visibility, $accessDepartments, $accessMemberIds),
        'tags' => $tags,
        'manual_tags' => $manualTags,
        'generated_tags' => $generatedTags,
        'access_member_ids' => $accessMemberIds,
        'inherited_pages' => count($inheritedPageIds),
        'notice' => $publicRequestBlocked ? '親ページが非公開のため、このページを公開に設定できません。' : null,
        'rag' => $rag,
        'affected_publications' => $affectedPublications,
    ]);
}

if ($action === 'favorite' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $id = (int) ($body['id'] ?? 0);
    beginPageWriteTransaction($pdo);
    $abortFavorite = static function (array $payload, int $status) use ($pdo): never {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse($payload, $status);
    };
    try {
        $liveUser = lockedAuthenticatedApplicationUserForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        if ($liveUser === null) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
            $abortFavorite(['error' => 'ログイン状態が変更されました。もう一度ログインしてください。'], 401);
        }
        $user = $liveUser;
        $lockedHierarchy = lockedPageHierarchiesForUpdate($pdo, [$id]);
        $page = $lockedHierarchy['rows'][$id] ?? null;
        if ($lockedHierarchy['reason'] !== null || !is_array($page) || !canViewPage($pdo, $user, $page)) {
            $abortFavorite(['error' => 'このページを閲覧する権限がありません。'], 403);
        }
        $statement = $pdo->prepare('UPDATE pages SET is_favorite = CASE WHEN is_favorite = 1 THEN 0 ELSE 1 END WHERE id = ?');
        $statement->execute([$id]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    jsonResponse(['ok' => true]);
}

if ($action === 'archive-page' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $id = (int) ($body['id'] ?? 0);
    beginPageWriteTransaction($pdo);
    $abortArchive = static function (array $payload, int $status) use ($pdo): never {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse($payload, $status);
    };
    try {
        $liveUser = lockedAuthenticatedApplicationUserForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        if ($liveUser === null) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
            $abortArchive(['error' => 'ログイン状態が変更されました。もう一度ログインしてください。'], 401);
        }
        $user = $liveUser;
        $subtree = lockedPageSubtreeForUpdate($pdo, $id, true);
        if ($subtree['reason'] !== null || !isset($subtree['rows'][$id])) {
            $status = $subtree['reason'] === 'missing' ? 403 : 409;
            $abortArchive([
                'error' => $status === 403
                    ? 'このページを削除する権限がありません。'
                    : 'ページ階層が別の操作で更新されました。もう一度お試しください。',
                'code' => $status === 409 ? 'page_hierarchy_conflict' : 'page_unavailable',
            ], $status);
        }
        $pages = $subtree['rows'];
        foreach ($pages as $page) {
            if (!canEditPage($pdo, $user, $page)) {
                $abortArchive(['error' => 'このページを削除する権限がありません。'], 403);
            }
        }
        $ids = $subtree['ids'];
        $publicationRoots = (new PublicPageSharing($pdo))->enabledPublicationRootIdsForPages($ids);
        if ($publicationRoots !== []) {
            $abortArchive([
                'error' => 'このページは静的Webサイトで公開中です。先に公開対象から外すか、Web公開を停止してください。',
                'code' => 'active_publication_requires_stop',
                'publication_root_ids' => $publicationRoots,
            ], 409);
        }
        $placeholders = sqlPlaceholders($ids);
        $parameters = [(int) $user['id'], ...$ids];
        $statement = $pdo->prepare("UPDATE pages SET archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP), updated_by = ?, is_favorite = 0 WHERE id IN ({$placeholders})");
        $statement->execute($parameters);
        invalidateRagPageGenerations($pdo, $ids);
        audit($pdo, (int) $user['id'], 'archived', 'page', $id, ['page_count' => count($ids)]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    foreach ($ids as $archivedId) {
        (new RagPipeline($pdo))->deactivatePage((int) $archivedId);
    }
    jsonResponse(['ok' => true, 'page_count' => count($ids)]);
}

if ($action === 'restore-page' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $id = (int) ($body['id'] ?? 0);
    beginPageWriteTransaction($pdo);
    $abortRestorePage = static function (array $payload, int $status) use ($pdo): never {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse($payload, $status);
    };
    try {
        $liveUser = lockedAuthenticatedApplicationUserForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        if ($liveUser === null) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
            $abortRestorePage(['error' => 'ログイン状態が変更されました。もう一度ログインしてください。'], 401);
        }
        $user = $liveUser;
        $subtree = lockedPageSubtreeForUpdate($pdo, $id, true);
        $rootPage = $subtree['rows'][$id] ?? null;
        if ($subtree['reason'] !== null || !is_array($rootPage)) {
            $status = $subtree['reason'] === 'missing' ? 404 : 409;
            $abortRestorePage([
                'error' => $status === 404
                    ? 'ゴミ箱にページが見つかりません。'
                    : 'ページ階層が別の操作で更新されました。もう一度お試しください。',
                'code' => $status === 409 ? 'page_hierarchy_conflict' : 'page_unavailable',
            ], $status);
        }
        if ($rootPage['archived_at'] === null) {
            $abortRestorePage(['error' => 'ゴミ箱にページが見つかりません。'], 404);
        }

        $archivedAncestorIds = [];
        foreach ($subtree['hierarchy_rows'] as $hierarchyPageId => $hierarchyPage) {
            if ((int) $hierarchyPageId !== $id && $hierarchyPage['archived_at'] !== null) {
                $archivedAncestorIds[] = (int) $hierarchyPageId;
            }
        }
        $ids = array_values(array_unique([...$archivedAncestorIds, ...$subtree['ids']]));
        $pages = [];
        foreach ($ids as $pageId) {
            $page = $subtree['rows'][$pageId] ?? $subtree['hierarchy_rows'][$pageId] ?? null;
            if (!is_array($page) || !canEditPage($pdo, $user, $page)) {
                $abortRestorePage(['error' => 'このページを復元する権限がありません。'], 403);
            }
            $pages[$pageId] = $page;
        }
        $placeholders = sqlPlaceholders($ids);
        $parameters = [(int) $user['id'], ...$ids];
        $statement = $pdo->prepare("UPDATE pages SET archived_at = NULL, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ({$placeholders})");
        $statement->execute($parameters);
        invalidateRagPageGenerations($pdo, $ids);
        audit($pdo, (int) $user['id'], 'restored_from_trash', 'page', $id, ['page_count' => count($ids)]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    foreach ($ids as $restoredId) {
        queueRagPage($pdo, (int) $restoredId);
    }
    jsonResponse(['ok' => true, 'page_count' => count($ids)]);
}

if ($action === 'delete-page-permanently' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin', 'content_admin']);
    $body = bodyJson();
    $id = (int) ($body['id'] ?? 0);
    beginPageWriteTransaction($pdo);
    $abortPermanentDelete = static function (array $payload, int $status) use ($pdo): never {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse($payload, $status);
    };
    try {
        $liveUser = lockedAuthenticatedApplicationUserForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        if ($liveUser === null) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
            $abortPermanentDelete(['error' => 'ログイン状態が変更されました。もう一度ログインしてください。'], 401);
        }
        $user = $liveUser;
        if (!in_array((string) ($user['role'] ?? ''), ['admin', 'content_admin'], true)) {
            $abortPermanentDelete(['error' => 'ページを完全に削除する権限がありません。'], 403);
        }
        $subtree = lockedPageSubtreeForUpdate($pdo, $id, true);
        $rootPage = $subtree['rows'][$id] ?? null;
        if ($subtree['reason'] !== null || !is_array($rootPage)) {
            $status = $subtree['reason'] === 'missing' ? 404 : 409;
            $abortPermanentDelete([
                'error' => $status === 404
                    ? 'ゴミ箱にページが見つかりません。'
                    : 'ページ階層が別の操作で更新されました。もう一度お試しください。',
                'code' => $status === 409 ? 'page_hierarchy_conflict' : 'page_unavailable',
            ], $status);
        }
        foreach ($subtree['rows'] as $page) {
            if ($page['archived_at'] === null) {
                $abortPermanentDelete([
                    'error' => '復元済みのページが含まれるため、完全削除を中止しました。',
                    'code' => 'page_subtree_conflict',
                ], 409);
            }
        }
        $ids = $subtree['ids'];
        $publicationRoots = (new PublicPageSharing($pdo))->enabledPublicationRootIdsForPages($ids);
        if ($publicationRoots !== []) {
            $abortPermanentDelete([
                'error' => 'このページは静的Webサイトで公開中です。先にWeb公開を停止してください。',
                'code' => 'active_publication_requires_stop',
                'publication_root_ids' => $publicationRoots,
            ], 409);
        }
        $reservedPublicationRoots = (new PublicPageSharing($pdo))->reservedPublicationRootIdsForPages($ids);
        if ($reservedPublicationRoots !== []) {
            $abortPermanentDelete([
                'error' => '固定公開URLの予約を保持するため、一度Web公開の親にしたページは完全削除できません。ゴミ箱で保管してください。',
                'code' => 'publication_url_reserved',
                'publication_root_ids' => $reservedPublicationRoots,
            ], 409);
        }
        $placeholders = sqlPlaceholders($ids);
        audit($pdo, (int) $user['id'], 'deleted_permanently', 'page', $id, ['page_count' => count($ids)]);
        $statement = $pdo->prepare("DELETE FROM pages WHERE id IN ({$placeholders})");
        $statement->execute($ids);
        $deleted = $statement->rowCount();
        if ($deleted !== count($ids)) {
            throw new RuntimeException('完全削除するページ集合が変更されました。');
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    jsonResponse(['ok' => true, 'page_count' => $deleted]);
}

if ($action === 'add-comment' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $pageId = (int) ($body['page_id'] ?? 0);
    $commentBody = cleanText((string) ($body['body'] ?? ''), 4000);
    if ($commentBody === '') {
        jsonResponse(['error' => 'コメントを入力してください。'], 422);
    }
    $parentId = !empty($body['parent_id']) ? (int) $body['parent_id'] : null;
    try {
        beginPageWriteTransaction($pdo);
        $context = lockedCommentWriteContext(
            $pdo,
            $user,
            $pageId,
            true,
            $expectedPasswordFingerprint
        );
        $user = $context['user'];
        $commentPage = $context['page'];

        if ($parentId) {
            $parentSql = 'SELECT id, parent_id FROM comments WHERE id = ? AND page_id = ?';
            if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $parentSql .= ' FOR UPDATE';
            }
            $parentCheck = $pdo->prepare($parentSql);
            $parentCheck->execute([$parentId, $pageId]);
            $parent = $parentCheck->fetch();
            if (!$parent) {
                throw new RuntimeException('返信先が見つかりません。', 404);
            }
            $parentId = $parent['parent_id'] ?: $parentId;
        }

        $statement = $pdo->prepare('INSERT INTO comments (page_id, parent_id, user_id, body) VALUES (?, ?, ?, ?)');
        $statement->execute([$pageId, $parentId, (int) $user['id'], $commentBody]);
        $id = (int) $pdo->lastInsertId();
        $notificationResult = notifyCommentParticipants($pdo, $user, $commentPage, $commentBody, $parentId);
        audit($pdo, (int) $user['id'], 'commented', 'page', $pageId, [
            'comment_id' => $id,
            'notification_count' => $notificationResult['count'],
            'mentioned_user_ids' => $notificationResult['mentioned_user_ids'],
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof RuntimeException && in_array((int) $exception->getCode(), [401, 403, 404, 409], true)) {
            if ((int) $exception->getCode() === 401) {
                unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
            }
            jsonResponse(['error' => $exception->getMessage()], (int) $exception->getCode());
        }
        throw $exception;
    }
    Hooks::action('comment_created', ['id' => $id, 'page_id' => $pageId]);
    jsonResponse([
        'ok' => true,
        'id' => $id,
        'notification_count' => $notificationResult['count'],
        'mentioned_user_ids' => $notificationResult['mentioned_user_ids'],
    ], 201);
}

if ($action === 'resolve-comment' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $id = (int) ($body['id'] ?? 0);
    $pageIdStatement = $pdo->prepare('SELECT page_id FROM comments WHERE id = ?');
    $pageIdStatement->execute([$id]);
    $pageId = (int) ($pageIdStatement->fetchColumn() ?: 0);
    if ($pageId < 1) {
        jsonResponse(['error' => 'このコメントを変更する権限がありません。'], 403);
    }

    try {
        beginPageWriteTransaction($pdo);
        $context = lockedCommentWriteContext(
            $pdo,
            $user,
            $pageId,
            false,
            $expectedPasswordFingerprint
        );
        $user = $context['user'];

        $commentSql = 'SELECT id FROM comments WHERE id = ? AND page_id = ?';
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $commentSql .= ' FOR UPDATE';
        }
        $commentStatement = $pdo->prepare($commentSql);
        $commentStatement->execute([$id, $pageId]);
        if (!$commentStatement->fetchColumn()) {
            throw new RuntimeException('このコメントを変更する権限がありません。', 403);
        }

        $statement = $pdo->prepare("UPDATE comments SET resolved_at = CASE WHEN resolved_at IS NULL THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id = ? AND page_id = ?");
        $statement->execute([$id, $pageId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof RuntimeException && in_array((int) $exception->getCode(), [401, 403, 404, 409], true)) {
            if ((int) $exception->getCode() === 401) {
                unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
            }
            jsonResponse(['error' => $exception->getMessage()], (int) $exception->getCode());
        }
        throw $exception;
    }
    jsonResponse(['ok' => true]);
}

if ($action === 'restore-revision' && $method === 'POST') {
    requireCsrf();
    requireRole($user, ['admin', 'content_admin']);
    $body = bodyJson();
    $revisionId = (int) ($body['revision_id'] ?? 0);
    $revision = $pdo->prepare('SELECT * FROM revisions WHERE id = ?');
    $revision->execute([$revisionId]);
    $snapshot = $revision->fetch();
    if (!$snapshot) {
        jsonResponse(['error' => '履歴が見つかりません。'], 404);
    }
    $meta = json_decode($snapshot['meta_json'], true) ?: [];
    $inheritedPageIds = [];
    $affectedPublications = [];
    beginPageWriteTransaction($pdo);
    $abortRestoreRevision = static function (array $payload, int $status) use ($pdo): never {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse($payload, $status);
    };
    try {
        $liveUser = lockedAuthenticatedApplicationUserForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        if ($liveUser === null) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
            $abortRestoreRevision(['error' => 'ログイン状態が変更されました。もう一度ログインしてください。'], 401);
        }
        $user = $liveUser;
        if (!in_array((string) ($user['role'] ?? ''), ['admin', 'content_admin'], true)) {
            $abortRestoreRevision(['error' => '履歴を復元する権限がありません。'], 403);
        }
        $pageId = (int) $snapshot['page_id'];
        $lockedHierarchy = lockedPageHierarchiesForUpdate($pdo, [$pageId]);
        if ($lockedHierarchy['reason'] !== null) {
            if ($lockedHierarchy['reason'] === 'missing'
                && (int) ($lockedHierarchy['page_id'] ?? 0) === $pageId) {
                $abortRestoreRevision(['error' => 'ページが見つかりません。'], 404);
            }
            $abortRestoreRevision([
                'error' => 'ページの階層が更新されました。もう一度お試しください。',
                'code' => 'page_hierarchy_conflict',
            ], 409);
        }
        $page = $lockedHierarchy['rows'][$pageId] ?? null;
        if (!$page) {
            $abortRestoreRevision(['error' => 'ページが見つかりません。'], 404);
        }

        $restoredStatus = in_array($meta['status'] ?? '', ['draft', 'review', 'published', 'private'], true) ? $meta['status'] : 'draft';
        $restoredVisibility = in_array($meta['visibility'] ?? '', ['company', 'department', 'group', 'private'], true) ? $meta['visibility'] : 'company';
        $restoredAccessDepartments = is_array($meta['access_departments'] ?? null)
            ? normalizePageAccessDepartments($meta['access_departments'])
            : normalizePageAccessDepartments([(string) ($meta['access_department'] ?? '')]);
        if ($restoredAccessDepartments === []) {
            $restoredAccessDepartments = pageAccessDepartments($pdo, (int) $page['id'], $page);
        }
        $restoredAccessDepartment = legacyPageAccessDepartment(
            $restoredAccessDepartments,
            (string) ($page['access_department'] ?? '')
        );
        if ($restoredStatus === 'private' || $restoredVisibility === 'private'
            || ($page['parent_id'] !== null && pageHierarchyForcesPrivate($pdo, (int) $page['parent_id']))) {
            $restoredStatus = 'private';
            $restoredVisibility = 'private';
        }
        $restoredMemberIds = $restoredVisibility === 'group'
            ? array_values(array_unique(array_filter(array_map('intval', (array) ($meta['access_member_ids'] ?? [])), static fn(int $memberId): bool => $memberId > 0)))
            : [];
        if (((string) ($page['status'] ?? '') === 'published' && $restoredStatus !== 'published')
            || $restoredStatus === 'private') {
            $publicationScopeIds = [$pageId];
            if ($restoredStatus === 'private') {
                $publicationSubtree = lockedPageSubtreeForUpdate($pdo, $pageId);
                if ($publicationSubtree['reason'] !== null) {
                    $abortRestoreRevision([
                        'error' => 'ページ階層が別の操作で更新されました。もう一度お試しください。',
                        'code' => 'page_hierarchy_conflict',
                    ], 409);
                }
                $publicationScopeIds = $publicationSubtree['ids'];
            }
            $publicationRoots = (new PublicPageSharing($pdo))->enabledPublicationRootIdsForPages($publicationScopeIds);
            if ($publicationRoots !== []) {
                $abortRestoreRevision([
                    'error' => 'このページは静的Webサイトで公開中です。先に公開対象から外すか、Web公開を停止してください。',
                    'code' => 'active_publication_requires_published_page',
                    'publication_root_ids' => $publicationRoots,
                ], 409);
            }
        }
        $backup = $pdo->prepare('INSERT INTO revisions (page_id, title, icon, blocks_json, meta_json, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $backup->execute([(int) $page['id'], $page['title'], $page['icon'], $page['blocks_json'], json_encode(['status' => $page['status'], 'category' => $page['category'], 'tags' => json_decode($page['tags_json'], true), 'manual_tags' => json_decode((string) ($page['manual_tags_json'] ?? ''), true), 'visibility' => $page['visibility'], 'access_department' => (string) ($page['access_department'] ?? ''), 'access_departments' => pageAccessDepartments($pdo, (int) $page['id'], $page), 'access_member_ids' => pageAccessMemberIds($pdo, (int) $page['id']), 'language_code' => (string) ($page['language_code'] ?? 'und'), 'content_revision' => (int) ($page['content_revision'] ?? 1), 'translation_status' => (string) ($page['translation_status'] ?? 'original'), 'source_revision' => $page['source_revision'] === null ? null : (int) $page['source_revision']], JSON_UNESCAPED_UNICODE), (int) $user['id']]);
        $restoredBlocks = json_decode((string) $snapshot['blocks_json'], true) ?: [];
        $restoredManualTags = uniquePageTags((array) ($meta['manual_tags'] ?? $meta['tags'] ?? []), 12);
        $restoredTagsJson = json_encode($restoredManualTags, JSON_UNESCAPED_UNICODE);
        $update = $pdo->prepare("UPDATE pages SET title = ?, icon = ?, blocks_json = ?, plain_text = ?, status = ?, category = ?, tags_json = ?, manual_tags_json = ?, visibility = ?, access_department = ?, content_revision = content_revision + 1, translation_status = CASE WHEN source_page_id IS NULL THEN translation_status ELSE 'human_edited' END, published_at = CASE WHEN ? = 'private' THEN NULL ELSE published_at END, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $update->execute([$snapshot['title'], $snapshot['icon'], $snapshot['blocks_json'], utf8Slice(blocksPlainText($restoredBlocks), 100000), $restoredStatus, $meta['category'] ?? 'ナレッジ', $restoredTagsJson, $restoredTagsJson, $restoredVisibility, $restoredAccessDepartment, $restoredStatus, (int) $user['id'], (int) $page['id']]);
        invalidateRagPageGenerations($pdo, [(int) $page['id']]);
        replacePageAccessDepartments($pdo, (int) $page['id'], $restoredAccessDepartments, (int) $user['id']);
        $pdo->prepare('DELETE FROM page_access_members WHERE page_id = ?')->execute([(int) $page['id']]);
        if ($restoredVisibility === 'group') {
            $grant = $pdo->prepare('INSERT INTO page_access_members (page_id, user_id, granted_by) VALUES (?, ?, ?)');
            foreach ($restoredMemberIds as $memberId) {
                $grant->execute([(int) $page['id'], $memberId, (int) $user['id']]);
            }
        }
        $inheritedPageIds = applyPageAccessInheritance(
            $pdo,
            (int) $page['id'],
            $restoredVisibility,
            $restoredAccessDepartments,
            $restoredMemberIds,
            $restoredStatus === 'private',
            (int) $user['id']
        );
        audit($pdo, (int) $user['id'], 'restored', 'page', (int) $page['id'], ['revision_id' => $revisionId]);
        $affectedPublications = $affectedPublicationsForPageIds([(int) $page['id'], ...$inheritedPageIds]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    $rag = queueRagPage($pdo, (int) $page['id']);
    foreach ($inheritedPageIds as $inheritedPageId) {
        queueRagPage($pdo, $inheritedPageId);
    }
    jsonResponse([
        'ok' => true,
        'page_id' => (int) $page['id'],
        'inherited_pages' => count($inheritedPageIds),
        'rag' => $rag,
        'affected_publications' => $affectedPublications,
    ]);
}

if ($action === 'notifications' && $method === 'GET') {
    $ownsPageSnapshot = beginConsistentPageRead($pdo);
    $snapshotUser = currentUser($pdo);
    if (!$snapshotUser) {
        if ($ownsPageSnapshot && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonResponse(['error' => 'ログインが必要です。'], 401);
    }
    $user = $snapshotUser;
    $rows = notificationsForUser($pdo, $user);
    $notificationResponse = [
        'notifications' => $rows,
        'unread_count' => count(array_filter($rows, static fn(array $notification): bool => !$notification['is_read'])),
    ];
    if ($ownsPageSnapshot) {
        $pdo->commit();
    }
    jsonResponse($notificationResponse);
}

if ($action === 'notification-read' && $method === 'POST') {
    requireCsrf();
    $body = bodyJson();
    $id = (int) ($body['id'] ?? 0);
    if ($id < 1) {
        jsonResponse(['error' => '通知を選択してください。'], 422);
    }
    $isRead = !array_key_exists('is_read', $body) || !empty($body['is_read']);
    $statement = $pdo->prepare('UPDATE notifications SET is_read = ? WHERE id = ? AND user_id = ?');
    $statement->execute([$isRead ? 1 : 0, $id, (int) $user['id']]);
    if ($statement->rowCount() < 1) {
        $exists = $pdo->prepare('SELECT 1 FROM notifications WHERE id = ? AND user_id = ?');
        $exists->execute([$id, (int) $user['id']]);
        if (!$exists->fetchColumn()) {
            jsonResponse(['error' => '通知が見つかりません。'], 404);
        }
    }
    jsonResponse(['ok' => true, 'id' => $id, 'is_read' => $isRead]);
}

if ($action === 'mark-notifications' && $method === 'POST') {
    requireCsrf();
    $statement = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
    $statement->execute([(int) $user['id']]);
    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => '操作が見つかりません。'], 404);
