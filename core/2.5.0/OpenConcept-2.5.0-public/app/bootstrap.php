<?php

declare(strict_types=1);

require_once __DIR__ . '/Environment.php';
Environment::loadProject(dirname(__DIR__));
require_once __DIR__ . '/Hooks.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/PluginManager.php';
require_once __DIR__ . '/PluginCatalog.php';
require_once __DIR__ . '/DeploymentRequirementException.php';
require_once __DIR__ . '/DeploymentRuntimeInspector.php';
require_once __DIR__ . '/RagStackAttestation.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DatabaseMigrationService.php';
require_once __DIR__ . '/DatabaseAdapterCoordinator.php';
require_once __DIR__ . '/DatabaseAdapterPluginController.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/InvitationManager.php';
require_once __DIR__ . '/RagProviderException.php';
require_once __DIR__ . '/SafeOutboundHttpClient.php';
require_once __DIR__ . '/AiProviderSettings.php';
require_once __DIR__ . '/TranslationProviderSettings.php';
require_once __DIR__ . '/OpenAIClient.php';
require_once __DIR__ . '/RagPipeline.php';
require_once __DIR__ . '/RagLoginWorker.php';
require_once __DIR__ . '/RagPluginContracts.php';
require_once __DIR__ . '/OpenConceptRagDocumentGateway.php';
require_once __DIR__ . '/RagProviderRegistry.php';
require_once __DIR__ . '/DeterministicBlockChunker.php';
require_once __DIR__ . '/BgeM3EmbeddingProvider.php';
require_once __DIR__ . '/StandardRagRepository.php';
require_once __DIR__ . '/ReciprocalRankFusion.php';
require_once __DIR__ . '/StandardRagRetriever.php';
require_once __DIR__ . '/CustomRagHttpConnector.php';
require_once __DIR__ . '/RagCoreRepository.php';
require_once __DIR__ . '/RagCoreService.php';
require_once __DIR__ . '/RetrievalRouteState.php';
require_once __DIR__ . '/RetrievalRouteResolution.php';
require_once __DIR__ . '/RetrievalRouteStateRepository.php';
require_once __DIR__ . '/RetrievalRouteSchemaRuntime.php';
require_once __DIR__ . '/RetrievalRouteTransitionService.php';
require_once __DIR__ . '/RetrievalRouteResolver.php';
require_once __DIR__ . '/RagAiSearchBridge.php';
require_once __DIR__ . '/OpenAiCompatibleChatProvider.php';
require_once __DIR__ . '/RagCoreController.php';
require_once __DIR__ . '/RagCoreRuntime.php';
require_once __DIR__ . '/AiValidationException.php';
require_once __DIR__ . '/AiSearchService.php';
require_once __DIR__ . '/AiPageActionService.php';
require_once __DIR__ . '/AiChatRepository.php';
require_once __DIR__ . '/PageExporter.php';
require_once __DIR__ . '/SafeHtml.php';
require_once __DIR__ . '/JsonRequestBody.php';
require_once __DIR__ . '/PageSaveInput.php';
require_once __DIR__ . '/DefaultOperationManual.php';
require_once __DIR__ . '/PublicPageRenderer.php';
require_once __DIR__ . '/StaticPublicSitePublisher.php';
require_once __DIR__ . '/PublicPageSharing.php';
require_once __DIR__ . '/TranslationProvider.php';
require_once __DIR__ . '/TranslationService.php';
require_once __DIR__ . '/PluginApiRuntime.php';
require_once __DIR__ . '/PluginApiController.php';
require_once __DIR__ . '/NotificationInbox.php';
require_once __DIR__ . '/ApplicationUpdateService.php';
require_once __DIR__ . '/PluginSearchBridge.php';

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

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(self), geolocation=()');
}

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function stopForCanonicalDatabaseFailure(DatabaseUnavailableException $exception): never
{
    if (PHP_SAPI === 'cli') {
        throw $exception;
    }
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: 30');
    $backend = htmlspecialchars($exception->backend(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $selectionFailure = in_array($exception->failureCode(), [
        'database_selection_conflict',
        'database_selection_guard_invalid',
        'database_selection_guard_unavailable',
    ], true);
    $category = $selectionFailure ? 'database_selection_conflict' : $exception->diagnosticCategory();
    $diagnostics = [
        'connection_unreachable' => [
            'title' => 'Database connection failed',
            'message' => 'OpenConcept could not reach the configured database endpoint.',
            'action' => 'Check that the database service is running and verify its host, port, DNS, and TLS settings.',
        ],
        'authentication_failed' => [
            'title' => 'Database authentication failed',
            'message' => 'The database server rejected the configured account credentials.',
            'action' => 'Verify the account name, password, authentication method, and credential.key backup set.',
        ],
        'permissions_insufficient' => [
            'title' => 'Database permissions are insufficient',
            'message' => 'The connected account does not satisfy the saved OpenConcept permission definition.',
            'action' => 'A database administrator must review the generated recovery SQL and execute it manually. OpenConcept never runs GRANT automatically.',
        ],
        'schema_mismatch' => [
            'title' => 'Database schema mismatch',
            'message' => 'The canonical schema does not match the signed OpenConcept schema definition.',
            'action' => 'Restore the matching database backup or apply the reviewed versioned schema migration. Automatic drift repair is disabled.',
        ],
        'configuration_error' => [
            'title' => 'Database configuration is invalid',
            'message' => 'OpenConcept could not validate the local adapter, credential, or permission-definition state.',
            'action' => 'Restore and verify the complete database backup set before restarting the service.',
        ],
    ];
    $diagnostic = $diagnostics[$category] ?? $diagnostics['configuration_error'];
    $details = $exception->details();
    $safeRecovery = [];
    foreach (['permission_definition', 'permission_definition_sha256', 'recovery_sql', 'recovery_sql_sha256', 'automatic_grant'] as $key) {
        if (isset($details[$key]) && (is_string($details[$key]) || is_bool($details[$key]))) {
            $safeRecovery[$key] = $details[$key];
        }
    }
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $script = strtolower((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($accept, 'application/json') || str_ends_with($script, '/api.php')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => $selectionFailure
                ? 'A conflicting, multiple, or unverifiable database selection was detected.'
                : $diagnostic['message'],
            'backend' => $exception->backend(),
            'failure_code' => $exception->failureCode(),
            'diagnostic_category' => $category,
            'automatic_fallback' => false,
            'automatic_grant' => false,
            'system_stopped' => true,
            'policy' => $selectionFailure
                ? 'OpenConcept stopped the system to protect canonical data.'
                : $diagnostic['action'],
            'recovery' => $safeRecovery === [] ? null : $safeRecovery,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        if ($selectionFailure) {
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
                . '<title>OpenConcept database selection conflict</title><body style="font-family:system-ui;max-width:720px;margin:10vh auto;padding:24px">'
                . '<h1>Database selection conflict</h1><p>OpenConcept detected a conflicting, multiple, or unverifiable selection for the <strong>' . $backend . '</strong> backend.</p>'
                . '<p><strong>OpenConcept stopped the system to protect canonical data.</strong></p>'
                . '<p lang="ja">正本データを保護するためにシステムを停止しました。</p>'
                . '<p>Do not start the application with another database selection. Restore the reviewed canonical configuration before service resumes.</p></body></html>';
        } else {
            $title = htmlspecialchars($diagnostic['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $message = htmlspecialchars($diagnostic['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $action = htmlspecialchars($diagnostic['action'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $failureCode = htmlspecialchars($exception->failureCode(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $recoveryHtml = '';
            if ($category === 'permissions_insufficient' && $safeRecovery !== []) {
                $definitionPath = htmlspecialchars((string) ($safeRecovery['permission_definition'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $recoveryPath = htmlspecialchars((string) ($safeRecovery['recovery_sql'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $recoveryHash = htmlspecialchars((string) ($safeRecovery['recovery_sql_sha256'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $recoveryHtml = '<h2>Administrator recovery files</h2><dl>'
                    . '<dt>Permission definition</dt><dd><code>' . $definitionPath . '</code></dd>'
                    . '<dt>Reviewable SQL</dt><dd><code>' . $recoveryPath . '</code></dd>'
                    . '<dt>SQL SHA-256</dt><dd><code>' . $recoveryHash . '</code></dd></dl>';
            }
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
                . '<title>OpenConcept ' . $title . '</title><body style="font-family:system-ui;max-width:760px;margin:8vh auto;padding:24px">'
                . '<h1>' . $title . '</h1><p>' . $message . '</p><p><strong>Backend:</strong> ' . $backend
                . ' / <strong>Failure code:</strong> <code>' . $failureCode . '</code></p><p>' . $action . '</p>'
                . $recoveryHtml . '<p lang="ja">OpenConcept は安全のため起動を停止しました。自動フォールバック、自動GRANT、自動スキーマ修復は行いません。</p></body></html>';
        }
    }
    exit;
}

try {
    $database = new Database(Environment::storagePath(dirname(__DIR__)));
} catch (DatabaseUnavailableException $exception) {
    stopForCanonicalDatabaseFailure($exception);
}
$pdo = $database->pdo();
$aiProviderSettings = new AiProviderSettings($database->storagePath());
$translationProviderSettings = new TranslationProviderSettings(
    $database->storagePath(),
    $aiProviderSettings
);
$i18n = new I18n('en-US');
$i18n->registerPackage('core', dirname(__DIR__) . '/locales');
$i18n->registerPackage('rag-core', dirname(__DIR__) . '/locales/rag-core');
$i18n->registerPackage('plugin-api', dirname(__DIR__) . '/locales/plugin-api');
$translationProviders = new TranslationProviderRegistry();

$pluginCompatibility = new PluginCompatibility(trim((string) file_get_contents(dirname(__DIR__) . '/VERSION')));
$pluginManager = new PluginManager(dirname(__DIR__) . '/plugins', true, $pluginCompatibility);
foreach ($pluginManager->plugins(false) as $installedPlugin) {
    $pluginLocaleDirectory = (string) ($installedPlugin['directory'] ?? '') . '/locales';
    if (is_dir($pluginLocaleDirectory)) {
        $i18n->registerPackage((string) $installedPlugin['id'], $pluginLocaleDirectory);
    }
}
$pluginStateRows = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'plugin.%'")->fetchAll();
$pluginStates = [];
foreach ($pluginStateRows as $pluginStateRow) {
    $settingKey = (string) ($pluginStateRow['setting_key'] ?? '');
    if (preg_match('/^plugin\.([a-z][a-z0-9]*(?:-[a-z0-9]+)*)\.enabled$/', $settingKey, $matches) === 1) {
        $pluginStates[$matches[1]] = (string) ($pluginStateRow['setting_value'] ?? '0') === '1';
    }
}
if (($database->backendId() === 'mysql' && $database->isManualExternalBackend())
    || $database->backendId() === 'postgresql') {
    // A working manually configured MySQL backend remains canonical, and a
    // PostgreSQL canonical backend supersedes its MySQL migration source. In
    // both cases only the migration plugin is disabled; DB state is untouched.
    $pluginStates['database-mysql-adapter'] = false;
}
$pluginManager->applyEnabledStates($pluginStates);
$pluginApiRuntime = new PluginApiRuntime($database, $pluginManager, dirname(__DIR__));
$pluginApiRegistry = $pluginApiRuntime->api;
$pluginFileService = $pluginApiRuntime->files;
$pluginApiRuntime->registerHooks();
$pluginSearchBridge = new PluginSearchBridge($pdo, $pluginApiRegistry);
$pluginSearchBridge->register();
(new ApplicationUpdateService($pluginApiRegistry->forCore('app-update'), $pluginApiRuntime->user(...), null, $i18n))->register();
$pluginSchemaOwnership = null;
$pluginSchemaProof = null;
$ragCoreRuntime = null;
$ragWorkspaceStatement = $pdo->prepare(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'rag.workspace_id'"
);
$ragWorkspaceStatement->execute();
$ragWorkspaceValue = $ragWorkspaceStatement->fetchColumn();
if (!is_string($ragWorkspaceValue) || preg_match('/^[a-f0-9]{32}$/D', $ragWorkspaceValue) !== 1) {
    $candidateWorkspaceId = bin2hex(random_bytes(16));
    try {
        $pdo->prepare(
            "INSERT INTO system_settings (setting_key, setting_value) VALUES ('rag.workspace_id', ?)"
        )->execute([$candidateWorkspaceId]);
        $ragWorkspaceValue = $candidateWorkspaceId;
    } catch (PDOException) {
        // A concurrent first request may have established the immutable ID.
        $ragWorkspaceStatement->execute();
        $ragWorkspaceValue = $ragWorkspaceStatement->fetchColumn();
    }
}
if (!is_string($ragWorkspaceValue) || preg_match('/^[a-f0-9]{32}$/D', $ragWorkspaceValue) !== 1) {
    throw new RuntimeException('OpenConcept RAG workspace identity is unavailable.');
}
$ragWorkspaceId = 'workspace-' . $ragWorkspaceValue;
$ragDocumentGateway = new OpenConceptRagDocumentGateway(
    $pdo,
    $ragWorkspaceId,
    static fn (array $user, array $page): bool => canViewPage($pdo, $user, $page)
);
try {
    if ($database->backendId() === 'mysql' && !$database->isManualExternalBackend()) {
        $adapterState = $database->adapterStateStore()->load()['adapters']['mysql'] ?? null;
        $adapterConfig = is_array($adapterState) ? ($adapterState['config'] ?? null) : null;
        if (!is_array($adapterConfig)) {
            throw new DatabaseUnavailableException(
                'mysql',
                'The canonical MySQL adapter state is incomplete.',
                null,
                'adapter_state_invalid'
            );
        }
        $workspaceIdentity = $database->adapterStateStore()->workspaceIdentity(
            (string) ($adapterConfig['prefix'] ?? '')
        );
        $pluginSchemaOwnership = new DatabaseWorkspaceOwnership($pdo, $workspaceIdentity);
        $pluginSchemaOwnership->acquire();
        $pluginSchemaProof = $pluginSchemaOwnership->verify();
    }
    $pluginManager->boot([
        'app_root' => dirname(__DIR__),
        'database' => $database,
        'pdo' => $pdo,
        'i18n' => $i18n,
        'translation_providers' => $translationProviders,
        'translation_provider_settings' => $translationProviderSettings,
        'ai_provider_settings' => $aiProviderSettings,
        'database_capabilities' => $database->capabilities(),
        'rag_documents' => $ragDocumentGateway,
        'plugin_api_registry' => $pluginApiRegistry,
    ]);
    // Plugin migrations may create plugin_* relations only. Re-prove the
    // complete Core36 contract before any application service is exposed.
    $database->verifyCanonicalSchema();
    $ragCoreRuntime = RagCoreRuntime::boot(
        $pdo,
        $ragDocumentGateway,
        $database->capabilities(),
        $database->backendId(),
        $aiProviderSettings
    );
    $retrievalRouteSchemaRuntime = RetrievalRouteSchemaRuntime::boot($pdo);
    if ($pluginSchemaOwnership !== null && is_array($pluginSchemaProof)) {
        $workspaceOwnership = $pluginSchemaOwnership->reconcileTrustedSchema(
            (new DatabaseSchemaInspector())->inspect($pdo),
            $pluginSchemaProof
        );
        if (($workspaceOwnership['changed'] ?? false) === true) {
            unset(
                $workspaceOwnership['changed'],
                $workspaceOwnership['added_tables'],
                $workspaceOwnership['removed_tables']
            );
            $database->adapterStateStore()->setLifecycle('mysql', 'ACTIVE', [
                'workspace_ownership' => $workspaceOwnership,
                'last_error_code' => null,
                'last_error' => null,
            ]);
        }
    }
} catch (Throwable $exception) {
    if ($database->backendId() === 'mysql' && !$database->isManualExternalBackend()) {
        try {
            $database->adapterStateStore()->recordFailure(
                'mysql',
                'plugin_schema_ownership',
                $exception instanceof DatabaseAdapterException
                    ? $exception->failureCode()
                    : 'workspace_manifest_mismatch',
                'The managed MySQL plugin schema ownership check failed.',
                true
            );
        } catch (Throwable) {
        }
    }
    if ($exception instanceof CoreSchemaException) {
        $exception = new DatabaseUnavailableException(
            $database->backendId(),
            'The canonical OpenConcept database schema is inconsistent.',
            $exception,
            $exception->failureCode()
        );
    }
    if ($exception instanceof DatabaseUnavailableException) {
        if ($pluginSchemaOwnership !== null) {
            $pluginSchemaOwnership->release();
            $pluginSchemaOwnership = null;
        }
        stopForCanonicalDatabaseFailure($exception);
    }
    throw $exception;
} finally {
    if ($pluginSchemaOwnership !== null) {
        $pluginSchemaOwnership->release();
    }
}

function jsonResponse(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Finish a successful JSON request before running non-critical maintenance.
 *
 * PHP-FPM can detach the response immediately. Other SAPIs receive the same
 * ordering and an explicit flush; maintenance failures are deliberately kept
 * outside the completed user operation.
 */
function jsonResponseWithDeferredWork(mixed $data, callable $afterResponse, int $status = 200): never
{
    $encoded = json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $outputCompression = strtolower(trim((string) ini_get('zlib.output_compression')));
    if ($outputCompression === '' || in_array($outputCompression, ['0', 'off'], true)) {
        header('Content-Length: ' . strlen($encoded));
    }
    echo $encoded;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    try {
        $afterResponse();
    } catch (Throwable $exception) {
        error_log('OpenConcept deferred request work failed: ' . $exception->getMessage());
    }
    exit;
}

function bodyJson(): array
{
    static $body = null;
    if (is_array($body)) {
        return $body;
    }
    $encoding = (string) ($_SERVER['HTTP_X_OPENCONCEPT_BODY_ENCODING'] ?? '');
    if ($encoding !== '') {
        // This flag is set only by api.php after live session authentication.
        // Always require CSRF here as plugins also consume this shared helper.
        requireCsrf();
    }
    $maximumBytes = JsonRequestBody::maximumWireBytes($encoding);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maximumBytes) {
        jsonResponse(['error' => '送信データが上限の16MiBを超えています。', 'code' => 'request_body_too_large'], 413);
    }
    $input = fopen('php://input', 'rb');
    $raw = is_resource($input) ? stream_get_contents($input, $maximumBytes + 1) : false;
    if (is_resource($input)) {
        fclose($input);
    }
    if (!is_string($raw)) {
        jsonResponse(['error' => '送信データを読み込めませんでした。', 'code' => 'request_body_unreadable'], 400);
    }
    try {
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $encodedAllowed = ($GLOBALS['openconceptEncodedJsonAllowed'] ?? false) === true;
        $encodedKey = $GLOBALS['openconceptEncryptedJsonKey'] ?? null;
        $requestAction = (string) ($_GET['action'] ?? '');
        $requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (($_GET['action'] ?? '') === 'save-page') {
            $nativeBody = null;
            $body = JsonRequestBody::decode($raw, $encoding, $contentType, $encodedAllowed, $encodedKey, $requestAction, $requestMethod, $nativeBody);
            $GLOBALS['openconceptJsonNativeBody'] = $nativeBody;
        } else {
            $body = JsonRequestBody::decode($raw, $encoding, $contentType, $encodedAllowed, $encodedKey, $requestAction, $requestMethod);
        }
        return $body;
    } catch (JsonRequestBodyException $exception) {
        jsonResponse(['error' => $exception->getMessage(), 'code' => $exception->failureCode], (int) $exception->getCode());
    }
}

function currentUser(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $statement = $pdo->prepare("SELECT id, name, email, password_hash, avatar_color, avatar_kind, avatar_value, ui_locale, role, department, must_change_password, invited_at, last_login_at FROM users WHERE id = ? AND active = 1 AND role <> 'suspended'");
    $statement->execute([(int) $_SESSION['user_id']]);
    $user = $statement->fetch() ?: null;
    if (!$user) {
        unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        return null;
    }

    $fingerprint = hash('sha256', (string) $user['password_hash']);
    $sessionFingerprint = $_SESSION['user_password_fingerprint'] ?? null;
    if (is_string($sessionFingerprint) && !hash_equals($fingerprint, $sessionFingerprint)) {
        unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
        return null;
    }
    if (!is_string($sessionFingerprint)) {
        $suspension = $pdo->prepare("SELECT 1 FROM audit_logs WHERE action = 'member_account_suspended' AND subject_type = 'user' AND subject_id = ? LIMIT 1");
        $suspension->execute([(int) $user['id']]);
        if ($suspension->fetchColumn()) {
            unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
            return null;
        }
        $_SESSION['user_password_fingerprint'] = $fingerprint;
    }

    unset($user['password_hash']);
    $user['id'] = (int) $user['id'];
    $user['must_change_password'] = (bool) $user['must_change_password'];
    return $user;
}

function requireUser(PDO $pdo): array
{
    $user = currentUser($pdo);
    if (!$user) {
        jsonResponse(['error' => 'ログインが必要です。'], 401);
    }
    return $user;
}

function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
        jsonResponse(['error' => 'セッションの確認に失敗しました。再読み込みしてください。'], 419);
    }
}

function setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $statement = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    $delete = $pdo->prepare('DELETE FROM system_settings WHERE setting_key = ?');
    $delete->execute([$key]);
    $insert = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
    $insert->execute([$key, $value]);
}

/** @return array{waf_compatibility_enabled: bool, waf_compatibility_key: ?string} */
function wafCompatibilitySettings(PDO $pdo, bool $authenticated): array
{
    $enabled = $authenticated && setting($pdo, JsonRequestBody::SETTING_KEY, '0') === '1';
    $key = $enabled ? setting($pdo, JsonRequestBody::KEY_SETTING) : null;
    return [
        'waf_compatibility_enabled' => $enabled,
        'waf_compatibility_key' => JsonRequestBody::decodeKey($key) !== null ? $key : null,
    ];
}

function isInitialized(PDO $pdo): bool
{
    return setting($pdo, 'initialized', '0') === '1';
}

function requireRole(array $user, array $roles): void
{
    if (!in_array($user['role'], $roles, true)) {
        jsonResponse(['error' => 'この操作を行う権限がありません。'], 403);
    }
}

/**
 * Locks every active system administrator in a deterministic order. Member
 * administration uses this set as the serialization boundary so concurrent
 * demotions or suspensions can never remove the last administrator.
 *
 * @return array<int, array<string, mixed>>
 */
function lockedActiveAdministratorsForWrite(PDO $pdo): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Administrators can only be locked inside a transaction.');
    }
    $sql = "SELECT * FROM users WHERE active = 1 AND role = 'admin' ORDER BY id";
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
    }
    unset($row);
    return $rows;
}

/**
 * Authenticates the request administrator against the already locked active
 * administrator set and the password fingerprint captured at request start.
 *
 * @return array{actor: array<string, mixed>, administrators: array<int, array<string, mixed>>}
 */
function requireLockedAdministratorSetForWrite(
    PDO $pdo,
    array $requestUser,
    string $expectedPasswordFingerprint
): array {
    $administrators = lockedActiveAdministratorsForWrite($pdo);
    $actorId = (int) ($requestUser['id'] ?? 0);
    $actor = null;
    foreach ($administrators as $administrator) {
        if ((int) $administrator['id'] === $actorId) {
            $actor = $administrator;
            break;
        }
    }
    if ($actor === null
        || !validPasswordFingerprint($expectedPasswordFingerprint)
        || !hash_equals(
            hash('sha256', (string) ($actor['password_hash'] ?? '')),
            $expectedPasswordFingerprint
        )) {
        throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
    }
    return ['actor' => $actor, 'administrators' => $administrators];
}

/** @param array<string, mixed> $lockedUser */
function requireCurrentPasswordForSensitiveAction(array $lockedUser, string $currentPassword): void
{
    if ($currentPassword === ''
        || strlen($currentPassword) > 4096
        || !password_verify($currentPassword, (string) ($lockedUser['password_hash'] ?? ''))) {
        throw new RuntimeException('現在のパスワードが正しくありません。', 422);
    }
}

/**
 * Changes an application account name. Only a currently active system
 * administrator may perform this action, including for their own account.
 *
 * @return array<string, mixed>
 */
function updateMemberName(PDO $pdo, array $actor, int $memberId, string $name): array
{
    $name = cleanText($name, 120);
    if ($memberId < 1 || $name === '') {
        throw new RuntimeException('アカウントと名前を確認してください。', 422);
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        beginPageWriteTransaction($pdo);
    }

    try {
        $administrators = lockedActiveAdministratorsForWrite($pdo);
        $actorId = (int) ($actor['id'] ?? 0);
        $administratorIds = array_map(static fn(array $item): int => (int) $item['id'], $administrators);
        if (!in_array($actorId, $administratorIds, true)) {
            throw new RuntimeException('この操作を行う権限がありません。', 403);
        }

        $member = lockedApplicationUserForUpdate($pdo, $memberId);
        if ($member === null || (string) ($member['role'] ?? '') === 'system') {
            throw new RuntimeException('名前を変更できるアカウントが見つかりません。', 404);
        }

        $previousName = (string) $member['name'];
        if ($previousName !== $name) {
            $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$name, $memberId]);
            audit($pdo, $actorId, 'member_name_updated', 'user', $memberId, [
                'previous_name' => $previousName,
                'name' => $name,
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        unset($member['password_hash']);
        $member['id'] = $memberId;
        $member['name'] = $name;
        return $member;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Changes an active member role while preserving at least one active system
 * administrator.
 *
 * @return array<string, mixed>
 */
function updateMemberRole(PDO $pdo, array $actor, int $memberId, string $role): array
{
    $allowedRoles = ['admin', 'content_admin', 'editor', 'author', 'commenter', 'viewer'];
    if ($memberId < 1 || !in_array($role, $allowedRoles, true)) {
        throw new RuntimeException('メンバーと役割を確認してください。', 422);
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        beginPageWriteTransaction($pdo);
    }

    try {
        $administrators = lockedActiveAdministratorsForWrite($pdo);
        $actorId = (int) ($actor['id'] ?? 0);
        $administratorIds = array_map(static fn(array $item): int => (int) $item['id'], $administrators);
        if (!in_array($actorId, $administratorIds, true)) {
            throw new RuntimeException('この操作を行う権限がありません。', 403);
        }
        if ($memberId === $actorId) {
            throw new RuntimeException('自分自身のシステム管理者権限は変更できません。', 409);
        }

        $member = lockedApplicationUserForUpdate($pdo, $memberId);
        if ($member === null
            || !(bool) ($member['active'] ?? false)
            || in_array((string) ($member['role'] ?? ''), ['system', 'suspended'], true)) {
            throw new RuntimeException('役割を変更できるメンバーが見つかりません。', 404);
        }

        $previousRole = (string) $member['role'];
        if ($previousRole === 'admin' && $role !== 'admin' && count($administrators) <= 1) {
            throw new RuntimeException('最後のシステム管理者は降格できません。', 409);
        }
        if ($previousRole !== $role) {
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $memberId]);
            audit($pdo, $actorId, 'member_role_updated', 'user', $memberId, [
                'previous_role' => $previousRole,
                'role' => $role,
                'administrator_privilege_changed' => $previousRole === 'admin' || $role === 'admin',
            ]);
            $message = $role === 'admin'
                ? 'あなたのアカウントにシステム管理者権限が付与されました。'
                : ($previousRole === 'admin'
                    ? 'あなたのシステム管理者権限が解除されました。'
                    : 'あなたのアカウントの役割が変更されました。');
            createNotification($pdo, $memberId, 'account_role_changed', $message, null);
            if ($previousRole === 'admin' || $role === 'admin') {
                $currentAdministratorIds = $pdo->query(
                    "SELECT id FROM users WHERE active = 1 AND role = 'admin' ORDER BY id"
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($currentAdministratorIds as $administratorId) {
                    $administratorId = (int) $administratorId;
                    if ($administratorId === $actorId || $administratorId === $memberId) {
                        continue;
                    }
                    createNotification(
                        $pdo,
                        $administratorId,
                        'administrator_role_changed',
                        (string) $member['name'] . 'さんのシステム管理者権限が変更されました。',
                        null
                    );
                }
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        unset($member['password_hash']);
        $member['id'] = $memberId;
        $member['role'] = $role;
        return $member;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Permanently blocks an account without deleting its identity record. Keeping
 * the row preserves the unique email address so the account cannot be invited
 * or registered again.
 *
 * @return array<string, mixed>|null
 */
function suspendMemberAccount(PDO $pdo, array $actor, int $memberId): ?array
{
    $actorId = (int) ($actor['id'] ?? 0);
    if ($memberId < 1 || $memberId === $actorId) {
        return null;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        beginPageWriteTransaction($pdo);
    }

    try {
        $administrators = lockedActiveAdministratorsForWrite($pdo);
        $administratorIds = array_map(static fn(array $item): int => (int) $item['id'], $administrators);
        if (!in_array($actorId, $administratorIds, true)) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }

        $member = lockedApplicationUserForUpdate($pdo, $memberId);
        if ($member === null
            || !(bool) ($member['active'] ?? false)
            || in_array((string) ($member['role'] ?? ''), ['system', 'suspended'], true)) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }

        $previousRole = (string) $member['role'];
        if ($previousRole === 'admin' && count($administrators) <= 1) {
            throw new RuntimeException('最後のシステム管理者は停止できません。', 409);
        }
        $blockedPassword = password_hash(bin2hex(random_bytes(48)), PASSWORD_DEFAULT);
        $update = $pdo->prepare(<<<'SQL'
UPDATE users
SET active = 0, role = 'suspended', password_hash = ?, must_change_password = 0
WHERE id = ?
  AND active = 1
  AND role NOT IN ('system', 'suspended')
SQL);
        $update->execute([$blockedPassword, $memberId]);
        if ($update->rowCount() !== 1) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }

        $pdo->prepare('DELETE FROM page_access_members WHERE user_id = ?')->execute([$memberId]);
        $pdo->prepare('DELETE FROM rag_source_access_members WHERE user_id = ?')->execute([$memberId]);
        audit($pdo, $actorId, 'member_account_suspended', 'user', $memberId, [
            'previous_role' => $previousRole,
            'login_blocked' => true,
            'registration_blocked' => true,
        ]);
        createNotification(
            $pdo,
            $memberId,
            'account_suspended',
            'あなたのアカウントはシステム管理者により停止されました。',
            null
        );
        if ($previousRole === 'admin') {
            foreach ($pdo->query("SELECT id FROM users WHERE active = 1 AND role = 'admin' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $administratorId) {
                $administratorId = (int) $administratorId;
                if ($administratorId !== $actorId) {
                    createNotification(
                        $pdo,
                        $administratorId,
                        'administrator_suspended',
                        (string) $member['name'] . 'さんのシステム管理者アカウントが停止されました。',
                        null
                    );
                }
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        $member['id'] = $memberId;
        $member['active'] = false;
        $member['role'] = 'suspended';
        $member['previous_role'] = $previousRole;
        $member['must_change_password'] = false;
        $member['initial_login_pending'] = false;
        return $member;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Reactivates a stopped account with a normal role and a new temporary
 * password. Existing sessions stay invalid because the password fingerprint
 * changes; only a fresh login with the invitation can authenticate again.
 *
 * @return array<string, mixed>|null
 */
function reactivateMemberAccount(PDO $pdo, array $actor, int $memberId, string $role, string $passwordHash): ?array
{
    $allowedRoles = ['admin', 'content_admin', 'editor', 'author', 'commenter', 'viewer'];
    $actorId = (int) ($actor['id'] ?? 0);
    if ($memberId < 1 || !in_array($role, $allowedRoles, true) || $passwordHash === '') {
        return null;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        beginPageWriteTransaction($pdo);
    }

    try {
        $administrators = lockedActiveAdministratorsForWrite($pdo);
        $administratorIds = array_map(static fn(array $item): int => (int) $item['id'], $administrators);
        if (!in_array($actorId, $administratorIds, true)) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }
        $member = lockedApplicationUserForUpdate($pdo, $memberId);
        if ($member === null
            || (bool) ($member['active'] ?? false)
            || (string) ($member['role'] ?? '') !== 'suspended') {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }

        $update = $pdo->prepare(<<<'SQL'
UPDATE users
SET active = 1, role = ?, password_hash = ?, must_change_password = 1, invited_at = CURRENT_TIMESTAMP
WHERE id = ?
  AND active = 0
  AND role = 'suspended'
SQL);
        $update->execute([$role, $passwordHash, $memberId]);
        if ($update->rowCount() !== 1) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }

        audit($pdo, $actorId, 'member_account_reactivated', 'user', $memberId, [
            'role' => $role,
            'password_reset_required' => true,
            'invitation_required' => true,
        ]);
        createNotification(
            $pdo,
            $memberId,
            'account_reactivated',
            $role === 'admin'
                ? 'あなたのアカウントがシステム管理者として再開されました。'
                : 'あなたのアカウントが再開されました。',
            null
        );

        if ($ownsTransaction) {
            $pdo->commit();
        }

        $member['id'] = $memberId;
        $member['active'] = true;
        $member['role'] = $role;
        $member['must_change_password'] = true;
        $member['initial_login_pending'] = false;
        $member['invited_at'] = date('Y-m-d H:i:s');
        return $member;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Replaces an active member password with a one-time password. Existing
 * sessions are invalidated by the password fingerprint boundary and the next
 * successful login must complete the normal forced password change.
 *
 * @return array<string, mixed>|null
 */
function resetMemberPassword(PDO $pdo, array $actor, int $memberId, string $passwordHash): ?array
{
    $actorId = (int) ($actor['id'] ?? 0);
    if ($memberId < 1 || $memberId === $actorId || $passwordHash === '') {
        return null;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        beginPageWriteTransaction($pdo);
    }

    try {
        $administrators = lockedActiveAdministratorsForWrite($pdo);
        $administratorIds = array_map(static fn(array $item): int => (int) $item['id'], $administrators);
        if (!in_array($actorId, $administratorIds, true)) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }

        $member = lockedApplicationUserForUpdate($pdo, $memberId);
        if ($member === null
            || !(bool) ($member['active'] ?? false)
            || in_array((string) ($member['role'] ?? ''), ['system', 'suspended'], true)) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }

        $update = $pdo->prepare(<<<'SQL'
UPDATE users
SET password_hash = ?, must_change_password = 1, invited_at = CURRENT_TIMESTAMP
WHERE id = ? AND active = 1 AND role NOT IN ('system', 'suspended')
SQL);
        $update->execute([$passwordHash, $memberId]);
        if ($update->rowCount() !== 1) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }

        audit($pdo, $actorId, 'member_password_reset', 'user', $memberId, [
            'role' => (string) $member['role'],
            'administrator_target' => (string) $member['role'] === 'admin',
            'password_reset_required' => true,
        ]);
        createNotification(
            $pdo,
            $memberId,
            'password_reset',
            'システム管理者によりパスワードが再設定されました。新しい一時パスワードでログインしてください。',
            null
        );

        if ($ownsTransaction) {
            $pdo->commit();
        }
        unset($member['password_hash']);
        $member['id'] = $memberId;
        $member['must_change_password'] = true;
        $member['invited_at'] = date('Y-m-d H:i:s');
        $member['initial_login_pending'] = false;
        return $member;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function canCreatePage(array $user): bool
{
    return in_array($user['role'], ['admin', 'content_admin', 'editor', 'author'], true);
}

function canManageAllContent(array $user): bool
{
    return in_array($user['role'], ['admin', 'content_admin'], true);
}

function uiLocale(?array $user = null): string
{
    global $i18n;
    if ($user !== null) {
        return $i18n->selectLocale((string) ($user['ui_locale'] ?? ''));
    }
    return $i18n->selectGuestLocale(
        is_string($_COOKIE['openconcept_ui_locale'] ?? null) ? $_COOKIE['openconcept_ui_locale'] : null,
        is_string($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : ''
    );
}

function rememberUiLocale(string $locale): void
{
    global $i18n;
    if (headers_sent() || $i18n->selectLocale($locale) !== $locale) {
        return;
    }
    $directory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    setcookie('openconcept_ui_locale', $locale, [
        'expires' => time() + 365 * 24 * 60 * 60,
        'path' => $directory === '.' ? '/' : rtrim($directory, '/') . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['openconcept_ui_locale'] = $locale;
}

/** @return array<string, mixed> */
function uiI18nBundle(?array $user = null): array
{
    global $i18n;
    return $i18n->bundle(uiLocale($user));
}

/** @return array<int, string> */
function organizationDepartments(PDO $pdo): array
{
    $rows = $pdo->query(<<<'SQL'
SELECT department
FROM users
WHERE active = 1
  AND role NOT IN ('system', 'suspended')
  AND TRIM(department) <> ''
SQL)->fetchAll(PDO::FETCH_COLUMN);

    $departments = [];
    foreach ($rows as $row) {
        $department = cleanText((string) $row, 120);
        if ($department !== '') {
            $departments[$department] = $department;
        }
    }
    $departments = array_values($departments);
    sort($departments, SORT_STRING);
    return $departments;
}

/** @return array<int, int> */
function pageAccessMemberIds(PDO $pdo, int $pageId): array
{
    $statement = $pdo->prepare('SELECT user_id FROM page_access_members WHERE page_id = ? ORDER BY user_id');
    $statement->execute([$pageId]);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Makes derived RAG data unreachable before a page/content ACL change commits.
 * Active chunks may be reused when the canonical content hash is unchanged,
 * but searches always require a matching current source document.
 *
 * @param array<int, mixed> $pageIds
 */
function invalidateRagPageGenerations(PDO $pdo, array $pageIds): void
{
    $pageIds = array_values(array_unique(array_filter(
        array_map('intval', $pageIds),
        static fn(int $pageId): bool => $pageId > 0
    )));
    if ($pageIds === []) {
        return;
    }
    $placeholders = sqlPlaceholders($pageIds);
    $statement = $pdo->prepare("UPDATE rag_source_documents SET is_current = 0 WHERE page_id IN ({$placeholders}) AND is_current = 1");
    $statement->execute($pageIds);
}

/**
 * @param array<int, mixed> $departments
 * @return array<int, string>
 */
function normalizePageAccessDepartments(array $departments, int $limit = 50): array
{
    $normalized = [];
    foreach (array_slice($departments, 0, max(1, $limit)) as $department) {
        if (!is_scalar($department) && $department !== null) {
            continue;
        }
        $department = cleanText((string) $department, 120);
        if ($department !== '' && !in_array($department, $normalized, true)) {
            $normalized[] = $department;
        }
    }
    sort($normalized, SORT_STRING);
    return $normalized;
}

/** @return array<int, string> */
function normalizedPageAccessDepartmentRows(PDO $pdo, int $pageId): array
{
    if ($pageId < 1) {
        return [];
    }
    $statement = $pdo->prepare('SELECT department FROM page_access_departments WHERE page_id = ? ORDER BY department');
    $statement->execute([$pageId]);
    return normalizePageAccessDepartments($statement->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Returns the normalized department ACL for one page. The junction table is
 * authoritative once it has at least one row. The legacy scalar is consulted
 * only for data created before the multi-department migration.
 *
 * @param array<string, mixed>|null $page
 * @return array<int, string>
 */
function pageAccessDepartments(PDO $pdo, int $pageId, ?array $page = null): array
{
    if ($pageId > 0) {
        $departments = normalizedPageAccessDepartmentRows($pdo, $pageId);
        if ($departments !== []) {
            return $departments;
        }
    }

    $accessDepartment = cleanText((string) ($page['access_department'] ?? ''), 120);
    $authorDepartment = cleanText((string) ($page['author_department'] ?? ''), 120);
    if ($pageId > 0 && ($accessDepartment === '' || $authorDepartment === '')) {
        $statement = $pdo->prepare(<<<'SQL'
SELECT p.access_department, u.department AS author_department
FROM pages p
JOIN users u ON u.id = p.author_id
WHERE p.id = ?
SQL);
        $statement->execute([$pageId]);
        $stored = $statement->fetch();
        if ($stored) {
            $accessDepartment = cleanText((string) ($stored['access_department'] ?? $accessDepartment), 120);
            $authorDepartment = cleanText((string) ($stored['author_department'] ?? $authorDepartment), 120);
        }
    } elseif ($authorDepartment === '' && (int) ($page['author_id'] ?? 0) > 0) {
        $statement = $pdo->prepare('SELECT department FROM users WHERE id = ? AND active = 1');
        $statement->execute([(int) $page['author_id']]);
        $authorDepartment = cleanText((string) ($statement->fetchColumn() ?: ''), 120);
    }

    return normalizePageAccessDepartments([$accessDepartment !== '' ? $accessDepartment : $authorDepartment]);
}

/**
 * @param array<int, mixed> $departments
 * @return array<int, string>
 */
function replacePageAccessDepartments(PDO $pdo, int $pageId, array $departments, int $actorId): array
{
    $departments = normalizePageAccessDepartments($departments);
    $pdo->prepare('DELETE FROM page_access_departments WHERE page_id = ?')->execute([$pageId]);
    if ($departments !== []) {
        $insert = $pdo->prepare('INSERT INTO page_access_departments (page_id, department, granted_by) VALUES (?, ?, ?)');
        foreach ($departments as $department) {
            $insert->execute([$pageId, $department, $actorId]);
        }
    }
    return $departments;
}

/** @param array<int, mixed> $departments */
function legacyPageAccessDepartment(array $departments, string $fallback = ''): string
{
    $departments = normalizePageAccessDepartments($departments);
    return $departments[0] ?? cleanText($fallback, 120);
}

/**
 * Resolves the scalar sent by a browser tab opened before multi-department
 * support. Echoing the stored mirror must not collapse the authoritative set.
 *
 * @param array<int, mixed> $existingDepartments
 * @return array<int, string>
 */
function pageAccessDepartmentsFromLegacyRequest(
    array $existingDepartments,
    string $requestedDepartment
): array
{
    $existingDepartments = normalizePageAccessDepartments($existingDepartments);
    $requestedDepartment = cleanText($requestedDepartment, 120);
    if ($existingDepartments !== []) {
        return $existingDepartments;
    }
    return normalizePageAccessDepartments([$requestedDepartment]);
}

/**
 * @param array<int, mixed> $departments
 * @param array<int, mixed> $memberIds
 * @return array{status: string, visibility: string, access_departments: array<int, string>, access_member_ids: array<int, int>}
 */
function canonicalPageAccessState(
    string $status,
    string $visibility,
    array $departments,
    array $memberIds
): array {
    $memberIds = array_values(array_unique(array_filter(
        array_map('intval', array_slice($memberIds, 0, 200)),
        static fn(int $memberId): bool => $memberId > 0
    )));
    sort($memberIds, SORT_NUMERIC);
    return [
        'status' => $status,
        'visibility' => $visibility,
        'access_departments' => normalizePageAccessDepartments($departments),
        'access_member_ids' => $memberIds,
    ];
}

/**
 * Resolves an optimistic sharing-state update. A stale request that did not
 * change sharing preserves the current state, while a stale explicit sharing
 * edit fails closed. Clients without an expected state are read-only for
 * sharing, including legacy clients that only know the scalar department.
 *
 * @param array<string, mixed> $currentState
 * @param array<string, mixed> $requestedState
 * @param array<string, mixed>|null $expectedState
 * @return array{state: array<string, mixed>, conflict: bool, preserved: bool}
 */
function resolvePageAccessStateUpdate(
    array $currentState,
    array $requestedState,
    ?array $expectedState
): array {
    $canonicalize = static fn(array $state): array => canonicalPageAccessState(
        (string) ($state['status'] ?? ''),
        (string) ($state['visibility'] ?? ''),
        (array) ($state['access_departments'] ?? []),
        (array) ($state['access_member_ids'] ?? [])
    );
    $currentState = $canonicalize($currentState);
    $requestedState = $canonicalize($requestedState);
    if ($expectedState === null) {
        return [
            'state' => $currentState,
            'conflict' => false,
            'preserved' => $requestedState !== $currentState,
        ];
    }

    $expectedState = $canonicalize($expectedState);
    if ($currentState === $expectedState) {
        return ['state' => $requestedState, 'conflict' => false, 'preserved' => false];
    }
    if ($requestedState === $expectedState) {
        return ['state' => $currentState, 'conflict' => false, 'preserved' => true];
    }
    return ['state' => $currentState, 'conflict' => true, 'preserved' => false];
}

function beginPageWriteTransaction(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        throw new LogicException('A page write transaction is already active.');
    }
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        // Some PDO SQLite versions on PHP 8.2 can execute BEGIN
        // IMMEDIATE without reflecting the transaction in inTransaction(),
        // commit(), or rollBack(). Start through PDO so every authorization guard and
        // caller observes the same boundary, then promote the deferred SQLite
        // transaction to a writer before any locking read. A zero-row UPDATE
        // acquires the same RESERVED writer lock without changing application
        // state or firing row triggers.
        $pdo->beginTransaction();
        try {
            $pdo->exec('UPDATE system_settings SET setting_value = setting_value WHERE 0');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return;
    }
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        // Path discovery happens before the locking reads. READ COMMITTED makes
        // the subsequent ACL and descendant queries observe the transaction
        // that may have completed while this writer was waiting for FOR UPDATE.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    $pdo->beginTransaction();
}

/**
 * Starts one database snapshot for a page payload and every ACL/ancestor read
 * used to authorize that payload. Returns false when the caller already owns
 * a transaction and must therefore also own its completion.
 */
function beginConsistentPageRead(PDO $pdo): bool
{
    if ($pdo->inTransaction()) {
        return false;
    }
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }
    $pdo->beginTransaction();
    return true;
}

function validPasswordFingerprint(string $fingerprint): bool
{
    return preg_match('/^[a-f0-9]{64}$/D', $fingerprint) === 1;
}

function sessionPasswordFingerprint(): ?string
{
    $fingerprint = $_SESSION['user_password_fingerprint'] ?? null;
    return is_string($fingerprint) && validPasswordFingerprint($fingerprint)
        ? $fingerprint
        : null;
}

/**
 * Reloads the request actor in the same repeatable-read snapshot as the data
 * that will be returned or materialized for an external service. A password
 * fingerprint is deliberately mandatory: callers must never turn a missing
 * session binding into a successful read.
 *
 * @param array<string, mixed> $requestUser
 * @return array<string, mixed>|null
 */
function authenticatedApplicationUserForReadSnapshot(
    PDO $pdo,
    array $requestUser,
    string $expectedPasswordFingerprint
): ?array {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Snapshot authentication requires an active transaction.');
    }
    if (!validPasswordFingerprint($expectedPasswordFingerprint)) {
        return null;
    }

    $statement = $pdo->prepare(<<<'SQL'
SELECT id, name, email, password_hash, avatar_color, avatar_kind, avatar_value, ui_locale,
       role, department, must_change_password, invited_at, last_login_at, active
FROM users
WHERE id = ? AND active = 1 AND role NOT IN ('system', 'suspended')
LIMIT 1
SQL);
    $statement->execute([(int) ($requestUser['id'] ?? 0)]);
    $liveUser = $statement->fetch();
    if (!is_array($liveUser)
        || !hash_equals(
            hash('sha256', (string) ($liveUser['password_hash'] ?? '')),
            $expectedPasswordFingerprint
        )) {
        return null;
    }

    $authenticatedUser = array_merge($requestUser, $liveUser);
    unset($authenticatedUser['password_hash']);
    $authenticatedUser['id'] = (int) $authenticatedUser['id'];
    $authenticatedUser['must_change_password'] = (bool) ($authenticatedUser['must_change_password'] ?? false);
    return $authenticatedUser;
}

/** @return array<string, mixed>|null */
function lockedActiveApplicationUserForWrite(PDO $pdo, int $userId): ?array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Application users can only be locked inside a transaction.');
    }
    $sql = "SELECT * FROM users WHERE id = ? AND active = 1 AND role NOT IN ('system', 'suspended')";
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $statement = $pdo->prepare($sql);
    $statement->execute([$userId]);
    $user = $statement->fetch();
    return is_array($user) ? $user : null;
}

/**
 * Reloads the request actor only after its user row has been serialized with
 * concurrent role, department, suspension, and password changes.
 *
 * @param array<string, mixed> $requestUser
 * @return array<string, mixed>|null
 */
function lockedAuthenticatedApplicationUserForWrite(
    PDO $pdo,
    array $requestUser,
    string $expectedPasswordFingerprint
): ?array {
    $liveUser = lockedActiveApplicationUserForWrite($pdo, (int) ($requestUser['id'] ?? 0));
    if ($liveUser === null) {
        return null;
    }
    if (!validPasswordFingerprint($expectedPasswordFingerprint)
        || !hash_equals(hash('sha256', (string) ($liveUser['password_hash'] ?? '')), $expectedPasswordFingerprint)) {
        return null;
    }
    unset($liveUser['password_hash']);
    $liveUser['id'] = (int) $liveUser['id'];
    return $liveUser;
}

/** @param array<string, mixed> $requestUser @return array<string, mixed>|null */
function lockedAuthenticatedApplicationAdminForWrite(
    PDO $pdo,
    array $requestUser,
    string $expectedPasswordFingerprint
): ?array {
    $liveUser = lockedAuthenticatedApplicationUserForWrite(
        $pdo,
        $requestUser,
        $expectedPasswordFingerprint
    );
    return $liveUser !== null && (string) ($liveUser['role'] ?? '') === 'admin'
        ? $liveUser
        : null;
}

/** @param array<string, mixed> $requestUser @return array<string, mixed> */
function requireLockedApplicationAdminForWrite(
    PDO $pdo,
    array $requestUser,
    string $expectedPasswordFingerprint
): array {
    $liveUser = lockedAuthenticatedApplicationUserForWrite(
        $pdo,
        $requestUser,
        $expectedPasswordFingerprint
    );
    if ($liveUser === null) {
        throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
    }
    if ((string) ($liveUser['role'] ?? '') !== 'admin') {
        throw new RuntimeException('この操作を行う権限がありません。', 403);
    }
    return $liveUser;
}

/** @return array<string, mixed>|null */
function lockedApplicationUserForUpdate(PDO $pdo, int $userId): ?array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Application users can only be locked inside a transaction.');
    }
    $sql = 'SELECT * FROM users WHERE id = ?';
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $statement = $pdo->prepare($sql);
    $statement->execute([$userId]);
    $user = $statement->fetch();
    return is_array($user) ? $user : null;
}

/**
 * Re-authorizes a comment writer only after the live user and complete page
 * hierarchy have been serialized with every competing page writer.
 *
 * @param array<string, mixed> $requestUser
 * @return array{user: array<string, mixed>, page: array<string, mixed>}
 */
function lockedCommentWriteContext(
    PDO $pdo,
    array $requestUser,
    int $pageId,
    bool $requireCommentsEnabled,
    string $expectedPasswordFingerprint
): array {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Comment authorization requires a page write transaction.');
    }

    $liveUser = lockedActiveApplicationUserForWrite($pdo, (int) ($requestUser['id'] ?? 0));
    if ($liveUser === null) {
        throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
    }
    if (!validPasswordFingerprint($expectedPasswordFingerprint)
        || !hash_equals(hash('sha256', (string) ($liveUser['password_hash'] ?? '')), $expectedPasswordFingerprint)) {
        throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
    }
    unset($liveUser['password_hash']);
    $liveUser['id'] = (int) $liveUser['id'];
    if ((string) ($liveUser['role'] ?? '') === 'viewer') {
        throw new RuntimeException('コメントを変更する権限がありません。', 403);
    }

    $lockedHierarchy = lockedPageHierarchiesForUpdate($pdo, [$pageId]);
    $livePage = $lockedHierarchy['rows'][$pageId] ?? null;
    if ($lockedHierarchy['reason'] !== null || !is_array($livePage)) {
        $status = $lockedHierarchy['reason'] === 'missing' ? 403 : 409;
        throw new RuntimeException(
            $status === 409
                ? 'ページが別の操作で更新されました。もう一度お試しください。'
                : 'このページを閲覧する権限がありません。',
            $status
        );
    }
    if (!canViewPage($pdo, $liveUser, $livePage)) {
        throw new RuntimeException('このページを閲覧する権限がありません。', 403);
    }
    if ($requireCommentsEnabled && empty($livePage['comments_enabled'])) {
        throw new RuntimeException('このページではコメントが無効です。', 403);
    }

    return ['user' => $liveUser, 'page' => $livePage];
}

/**
 * Re-authorize an upload at its database commit boundary. File inspection and
 * filesystem staging may take time, so no earlier user/page decision is
 * trusted for the final files row.
 *
 * @param array<string, mixed> $requestUser
 * @return array{user: array<string, mixed>, page: array<string, mixed>|null}
 */
function lockedUploadWriteContext(
    PDO $pdo,
    array $requestUser,
    int $pageId,
    string $expectedPasswordFingerprint
): array {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Upload authorization requires a page write transaction.');
    }
    $liveUser = lockedActiveApplicationUserForWrite($pdo, (int) ($requestUser['id'] ?? 0));
    if ($liveUser === null
        || !validPasswordFingerprint($expectedPasswordFingerprint)
        || !hash_equals(hash('sha256', (string) ($liveUser['password_hash'] ?? '')), $expectedPasswordFingerprint)) {
        throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
    }
    unset($liveUser['password_hash']);
    $liveUser['id'] = (int) $liveUser['id'];

    if ($pageId < 1) {
        if (!canCreatePage($liveUser)) {
            throw new RuntimeException('ファイルをアップロードする権限がありません。', 403);
        }
        return ['user' => $liveUser, 'page' => null];
    }

    $lockedHierarchy = lockedPageHierarchiesForUpdate($pdo, [$pageId]);
    $livePage = $lockedHierarchy['rows'][$pageId] ?? null;
    if ($lockedHierarchy['reason'] !== null || !is_array($livePage)) {
        throw new RuntimeException('アップロード先のページが更新されたか見つかりません。', 409);
    }
    if (!canEditPage($pdo, $liveUser, $livePage)) {
        throw new RuntimeException('このページにファイルを追加する権限がありません。', 403);
    }
    return ['user' => $liveUser, 'page' => $livePage];
}

/** @return array<string, mixed>|null */
function lockedPageForUpdate(PDO $pdo, int $pageId, bool $includeArchived = false): ?array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Page rows can only be locked inside a transaction.');
    }
    $sql = 'SELECT * FROM pages WHERE id = ?';
    if (!$includeArchived) {
        $sql .= ' AND archived_at IS NULL';
    }
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $statement = $pdo->prepare($sql);
    $statement->execute([$pageId]);
    $page = $statement->fetch();
    return $page ?: null;
}

/**
 * Locks every root-to-leaf path needed by a page write in a deterministic
 * order. Locking the ancestors first gives access propagation on an ancestor
 * a serialization point shared with child creation and page moves.
 *
 * The paths are discovered before locking, then every parent edge is checked
 * again from the locking reads. A concurrent move therefore becomes a retryable
 * conflict instead of letting the caller inherit an ACL from a stale parent.
 *
 * @param array<int, int> $pageIds
 * @return array{rows: array<int, array<string, mixed>>, reason: ?string, page_id: ?int}
 */
function lockedPageHierarchiesForUpdate(PDO $pdo, array $pageIds, bool $includeArchived = false): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Page hierarchies can only be locked inside a transaction.');
    }

    $pageIds = array_values(array_unique(array_filter(
        array_map('intval', $pageIds),
        static fn(int $pageId): bool => $pageId > 0
    )));
    sort($pageIds, SORT_NUMERIC);
    if ($pageIds === []) {
        return ['rows' => [], 'reason' => null, 'page_id' => null];
    }

    $readSql = 'SELECT * FROM pages WHERE id = ?';
    if (!$includeArchived) {
        $readSql .= ' AND archived_at IS NULL';
    }
    $read = $pdo->prepare($readSql);
    /** @var array<int, array<string, mixed>> $snapshotRows */
    $snapshotRows = [];
    /** @var array<int, array{root_id: int, depth: int, parent_id: ?int}> $lockNodes */
    $lockNodes = [];

    foreach ($pageIds as $leafId) {
        $path = [];
        $visited = [];
        $cursor = $leafId;
        while ($cursor > 0) {
            if (isset($visited[$cursor])) {
                return ['rows' => [], 'reason' => 'cycle', 'page_id' => $cursor];
            }
            $visited[$cursor] = true;
            $read->execute([$cursor]);
            $row = $read->fetch();
            if (!is_array($row)) {
                return ['rows' => [], 'reason' => 'missing', 'page_id' => $cursor];
            }
            $snapshotRows[$cursor] = $row;
            $path[] = $cursor;
            $cursor = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
        }

        $path = array_reverse($path);
        $rootId = (int) ($path[0] ?? 0);
        foreach ($path as $depth => $pathPageId) {
            $parentId = $snapshotRows[$pathPageId]['parent_id'] === null
                ? null
                : (int) $snapshotRows[$pathPageId]['parent_id'];
            $lockNodes[$pathPageId] = [
                'root_id' => $rootId,
                'depth' => $depth,
                'parent_id' => $parentId,
            ];
        }
    }

    $orderedIds = array_keys($lockNodes);
    usort($orderedIds, static function (int $leftId, int $rightId) use ($lockNodes): int {
        $left = $lockNodes[$leftId];
        $right = $lockNodes[$rightId];
        return $left['root_id'] <=> $right['root_id']
            ?: $left['depth'] <=> $right['depth']
            ?: $leftId <=> $rightId;
    });

    $lockedRows = [];
    foreach ($orderedIds as $pageId) {
        $locked = lockedPageForUpdate($pdo, $pageId, $includeArchived);
        if (!is_array($locked)) {
            return ['rows' => [], 'reason' => 'missing', 'page_id' => $pageId];
        }
        $lockedParentId = $locked['parent_id'] === null ? null : (int) $locked['parent_id'];
        if ($lockedParentId !== $lockNodes[$pageId]['parent_id']) {
            return ['rows' => [], 'reason' => 'changed', 'page_id' => $pageId];
        }
        $lockedRows[$pageId] = $locked;
    }

    foreach ($pageIds as $leafId) {
        $visited = [];
        $cursor = $leafId;
        while ($cursor > 0) {
            if (isset($visited[$cursor]) || !isset($lockedRows[$cursor])) {
                return ['rows' => [], 'reason' => 'changed', 'page_id' => $cursor];
            }
            $visited[$cursor] = true;
            $cursor = $lockedRows[$cursor]['parent_id'] === null
                ? 0
                : (int) $lockedRows[$cursor]['parent_id'];
        }
    }

    return ['rows' => $lockedRows, 'reason' => null, 'page_id' => null];
}

/**
 * Locks one complete page subtree after first locking the root's ancestor path.
 * The root row is the serialization point shared with create/move/save writers;
 * once held, the live descendant set cannot change without waiting for this
 * transaction. Descendants are then locked parent-first with stable ID order.
 *
 * @return array{
 *   rows: array<int, array<string, mixed>>,
 *   hierarchy_rows: array<int, array<string, mixed>>,
 *   ids: array<int, int>,
 *   reason: ?string,
 *   page_id: ?int
 * }
 */
function lockedPageSubtreeForUpdate(PDO $pdo, int $rootId, bool $includeArchived = false): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Page subtrees can only be locked inside a transaction.');
    }
    if ($rootId < 1) {
        return ['rows' => [], 'hierarchy_rows' => [], 'ids' => [], 'reason' => 'missing', 'page_id' => $rootId];
    }

    $hierarchy = lockedPageHierarchiesForUpdate($pdo, [$rootId], $includeArchived);
    if ($hierarchy['reason'] !== null) {
        return [
            'rows' => [],
            'hierarchy_rows' => [],
            'ids' => [],
            'reason' => $hierarchy['reason'],
            'page_id' => $hierarchy['page_id'],
        ];
    }

    $treeSql = 'SELECT id, parent_id FROM pages';
    if (!$includeArchived) {
        $treeSql .= ' WHERE archived_at IS NULL';
    }
    $treeRows = $pdo->query($treeSql)->fetchAll();
    $children = [];
    $parentById = [];
    foreach ($treeRows as $treeRow) {
        $pageId = (int) $treeRow['id'];
        $parentId = $treeRow['parent_id'] === null ? 0 : (int) $treeRow['parent_id'];
        $parentById[$pageId] = $treeRow['parent_id'] === null ? null : $parentId;
        $children[$parentId][] = $pageId;
    }
    foreach ($children as &$childIds) {
        sort($childIds, SORT_NUMERIC);
    }
    unset($childIds);

    $ids = [];
    $visited = [];
    $queue = [$rootId];
    for ($queueIndex = 0; $queueIndex < count($queue); $queueIndex++) {
        $pageId = $queue[$queueIndex];
        if (isset($visited[$pageId])) {
            return [
                'rows' => [],
                'hierarchy_rows' => $hierarchy['rows'],
                'ids' => [],
                'reason' => 'cycle',
                'page_id' => $pageId,
            ];
        }
        $visited[$pageId] = true;
        $ids[] = $pageId;
        foreach ($children[$pageId] ?? [] as $childId) {
            $queue[] = $childId;
        }
    }

    $rows = [];
    foreach ($ids as $pageId) {
        $locked = $hierarchy['rows'][$pageId] ?? lockedPageForUpdate($pdo, $pageId, $includeArchived);
        if (!is_array($locked)) {
            return [
                'rows' => [],
                'hierarchy_rows' => $hierarchy['rows'],
                'ids' => $ids,
                'reason' => 'missing',
                'page_id' => $pageId,
            ];
        }
        if ($pageId !== $rootId) {
            $expectedParentId = $parentById[$pageId] ?? null;
            $lockedParentId = $locked['parent_id'] === null ? null : (int) $locked['parent_id'];
            if ($lockedParentId !== $expectedParentId) {
                return [
                    'rows' => [],
                    'hierarchy_rows' => $hierarchy['rows'],
                    'ids' => $ids,
                    'reason' => 'changed',
                    'page_id' => $pageId,
                ];
            }
        }
        $rows[$pageId] = $locked;
    }

    return [
        'rows' => $rows,
        'hierarchy_rows' => $hierarchy['rows'],
        'ids' => $ids,
        'reason' => null,
        'page_id' => null,
    ];
}

function canViewPageDirect(PDO $pdo, array $user, array $page): bool
{
    if (canManageAllContent($user) || (int) $page['author_id'] === (int) $user['id']) {
        return true;
    }

    $visibility = (string) (($page['status'] ?? '') === 'private' ? 'private' : ($page['visibility'] ?? 'company'));
    if ($visibility === 'company') {
        return true;
    }
    if ($visibility === 'private') {
        return false;
    }
    if ($visibility === 'department') {
        $userDepartment = cleanText((string) ($user['department'] ?? ''), 120);
        if ($userDepartment === '') {
            return false;
        }
        foreach (pageAccessDepartments($pdo, (int) ($page['id'] ?? 0), $page) as $accessDepartment) {
            if (hash_equals($accessDepartment, $userDepartment)) {
                return true;
            }
        }
        return false;
    }
    if ($visibility === 'group') {
        $statement = $pdo->prepare('SELECT 1 FROM page_access_members WHERE page_id = ? AND user_id = ?');
        $statement->execute([(int) $page['id'], (int) $user['id']]);
        return (bool) $statement->fetchColumn();
    }
    return false;
}

function canViewPage(PDO $pdo, array $user, array $page): bool
{
    if (canManageAllContent($user)) {
        return true;
    }

    $pageId = (int) ($page['id'] ?? 0);
    if ($pageId < 1) {
        return canViewPageDirect($pdo, $user, $page);
    }

    $loadPage = static function (int $id) use ($pdo): ?array {
        $statement = $pdo->prepare(<<<'SQL'
SELECT p.*, u.department AS author_department
FROM pages p
JOIN users u ON u.id = p.author_id
WHERE p.id = ?
SQL);
        $statement->execute([$id]);
        $row = $statement->fetch();
        return $row ?: null;
    };

    // Always use the persisted row so callers cannot accidentally omit a
    // parent/status field and bypass inherited restrictions.
    $current = $loadPage($pageId);
    if (!$current || !canViewPageDirect($pdo, $user, $current)) {
        return false;
    }

    $visited = [$pageId => true];
    while ($current['parent_id'] !== null) {
        $parentId = (int) $current['parent_id'];
        if ($parentId < 1 || isset($visited[$parentId])) {
            return false;
        }
        $visited[$parentId] = true;
        $current = $loadPage($parentId);
        if (!$current || $current['archived_at'] !== null || !canViewPageDirect($pdo, $user, $current)) {
            return false;
        }
    }
    return true;
}

function canEditPage(PDO $pdo, array $user, array $page): bool
{
    if (canManageAllContent($user)) {
        return true;
    }
    if (!canViewPage($pdo, $user, $page)) {
        return false;
    }
    if ($user['role'] === 'editor') {
        return true;
    }
    $authorId = isset($page['author_id']) ? (int) $page['author_id'] : 0;
    if ($authorId < 1 && (int) ($page['id'] ?? 0) > 0) {
        $statement = $pdo->prepare('SELECT author_id FROM pages WHERE id = ?');
        $statement->execute([(int) $page['id']]);
        $authorId = (int) $statement->fetchColumn();
    }
    return $user['role'] === 'author' && $authorId === (int) $user['id'];
}

/** @param array<string, mixed>|null $page */
function pageReferencesFile(PDO $pdo, int $pageId, int $fileId, ?array $page = null): bool
{
    if ($pageId < 1 || $fileId < 1) {
        return false;
    }
    if ($page === null || (int) ($page['id'] ?? 0) !== $pageId || !array_key_exists('blocks_json', $page)) {
        // Archived pages still count as referenced. Treating them as
        // unreferenced would let the original uploader bypass the archived
        // page ACL through the cleanup-only fallback below.
        $statement = $pdo->prepare('SELECT id, blocks_json FROM pages WHERE id = ?');
        $statement->execute([$pageId]);
        $page = $statement->fetch() ?: null;
    }
    if (!$page) {
        return false;
    }
    $blocks = json_decode((string) ($page['blocks_json'] ?? '[]'), true);
    if (!is_array($blocks)) {
        return false;
    }
    foreach ($blocks as $block) {
        if (is_array($block) && (int) ($block['file_id'] ?? 0) === $fileId) {
            return true;
        }
    }
    return false;
}

/** @param array<string, mixed>|null $page */
function canViewFile(PDO $pdo, array $user, array $file, ?array $page = null): bool
{
    if (canManageAllContent($user)) {
        return true;
    }
    $pageId = (int) ($file['page_id'] ?? 0);
    $fileId = (int) ($file['id'] ?? 0);
    $isUploader = (int) ($file['uploaded_by'] ?? 0) === (int) ($user['id'] ?? 0);
    if ($pageId < 1) {
        return $isUploader;
    }
    if (!pageReferencesFile($pdo, $pageId, $fileId, $page)) {
        // Preserve unreferenced uploads for cleanup by their uploader, but do
        // not extend that exception to content still referenced by a page.
        return $isUploader;
    }
    if ($page === null || (int) ($page['id'] ?? 0) !== $pageId || !array_key_exists('author_id', $page)) {
        $statement = $pdo->prepare(<<<'SQL'
SELECT p.*, author.department AS author_department
FROM pages p
JOIN users author ON author.id = p.author_id
WHERE p.id = ? AND p.archived_at IS NULL
SQL);
        $statement->execute([$pageId]);
        $page = $statement->fetch() ?: null;
    }
    return $page !== null && canViewPage($pdo, $user, $page);
}

function canDeleteFile(PDO $pdo, array $user, array $file): bool
{
    if (canManageAllContent($user)) {
        return true;
    }
    $pageId = (int) ($file['page_id'] ?? 0);
    if ($pageId < 1) {
        return (int) ($file['uploaded_by'] ?? 0) === (int) ($user['id'] ?? 0);
    }
    if (!pageReferencesFile($pdo, $pageId, (int) ($file['id'] ?? 0))) {
        return (int) ($file['uploaded_by'] ?? 0) === (int) ($user['id'] ?? 0);
    }
    $statement = $pdo->prepare('SELECT * FROM pages WHERE id = ?');
    $statement->execute([$pageId]);
    $page = $statement->fetch();
    return $page && canEditPage($pdo, $user, $page);
}

/**
 * Builds one deterministic tree order from the complete page hierarchy, then
 * removes pages the user cannot view. Since canViewPage() checks every
 * ancestor, a hidden parent always removes its complete subtree.
 *
 * @param array<int, array<string, mixed>> $pages
 * @return array<int, array<string, mixed>>
 */
function visiblePageTree(PDO $pdo, array $user, array $pages): array
{
    $byId = [];
    foreach ($pages as $page) {
        $page['id'] = (int) $page['id'];
        $page['parent_id'] = $page['parent_id'] === null ? null : (int) $page['parent_id'];
        $page['sort_order'] = (int) ($page['sort_order'] ?? 0);
        $byId[$page['id']] = $page;
    }

    $children = [];
    foreach ($byId as $page) {
        $parentKey = $page['parent_id'] !== null && isset($byId[$page['parent_id']]) ? $page['parent_id'] : 0;
        $children[$parentKey][] = $page['id'];
    }
    $compareIds = static function (int $leftId, int $rightId) use (&$byId): int {
        return ($byId[$leftId]['sort_order'] <=> $byId[$rightId]['sort_order']) ?: ($leftId <=> $rightId);
    };
    foreach ($children as &$childIds) {
        usort($childIds, $compareIds);
    }
    unset($childIds);

    $treeOrder = [];
    $visiting = [];
    $sequence = 0;
    $visit = static function (int $pageId) use (&$visit, &$treeOrder, &$visiting, &$sequence, &$children): void {
        if (isset($treeOrder[$pageId]) || isset($visiting[$pageId])) {
            return;
        }
        $visiting[$pageId] = true;
        $treeOrder[$pageId] = ++$sequence;
        foreach ($children[$pageId] ?? [] as $childId) {
            $visit($childId);
        }
        unset($visiting[$pageId]);
    };
    foreach ($children[0] ?? [] as $rootId) {
        $visit($rootId);
    }
    $remainingIds = array_values(array_diff(array_keys($byId), array_keys($treeOrder)));
    usort($remainingIds, $compareIds);
    foreach ($remainingIds as $pageId) {
        $visit($pageId);
    }

    $visible = [];
    foreach ($byId as $pageId => $page) {
        if (!canViewPage($pdo, $user, $page)) {
            continue;
        }
        $page['tree_order'] = $treeOrder[$pageId];
        $visible[$pageId] = $page;
    }
    $visibleIds = array_fill_keys(array_keys($visible), true);
    foreach ($visible as $pageId => &$page) {
        $parentId = $byId[$pageId]['parent_id'];
        $seen = [];
        while ($parentId !== null && !isset($visibleIds[$parentId])) {
            if (isset($seen[$parentId]) || !isset($byId[$parentId])) {
                $parentId = null;
                break;
            }
            $seen[$parentId] = true;
            $parentId = $byId[$parentId]['parent_id'];
        }
        $page['parent_id'] = $parentId;
    }
    unset($page);

    $result = array_values($visible);
    usort($result, static fn(array $left, array $right): int => $left['tree_order'] <=> $right['tree_order']);
    return $result;
}

/** @return array<int, int> */
function descendantPageIds(PDO $pdo, int $rootId): array
{
    $rows = $pdo->query('SELECT id, parent_id FROM pages')->fetchAll();
    $children = [];
    foreach ($rows as $row) {
        $parentId = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
        $children[$parentId][] = (int) $row['id'];
    }

    $result = [];
    $queue = [$rootId];
    while ($queue) {
        $id = array_shift($queue);
        if (in_array($id, $result, true)) {
            continue;
        }
        $result[] = $id;
        foreach ($children[$id] ?? [] as $childId) {
            $queue[] = $childId;
        }
    }
    return $result;
}

function pageHierarchyForcesPrivate(PDO $pdo, int $pageId): bool
{
    $statement = $pdo->prepare('SELECT id, parent_id, status, visibility, archived_at FROM pages WHERE id = ?');
    $visited = [];
    $currentId = $pageId;
    while ($currentId > 0) {
        if (isset($visited[$currentId])) {
            return true;
        }
        $visited[$currentId] = true;
        $statement->execute([$currentId]);
        $page = $statement->fetch();
        if (!$page || $page['archived_at'] !== null) {
            return true;
        }
        if (($page['status'] ?? '') === 'private' || ($page['visibility'] ?? '') === 'private') {
            return true;
        }
        $currentId = $page['parent_id'] === null ? 0 : (int) $page['parent_id'];
    }
    return false;
}

/**
 * Persists one parent's access settings on its descendants. When includeRoot
 * is true (used after a move), the moved page itself also inherits them.
 *
 * @param array<int, string>|string $accessDepartments
 * @param array<int, int> $accessMemberIds
 * @return array<int, int> Updated page ids
 */
function applyPageAccessInheritance(
    PDO $pdo,
    int $rootId,
    string $visibility,
    array|string $accessDepartments,
    array $accessMemberIds,
    bool $forcePrivate,
    int $actorId,
    bool $includeRoot = false
): array {
    $pageIds = descendantPageIds($pdo, $rootId);
    if (!$includeRoot) {
        $pageIds = array_values(array_filter($pageIds, static fn(int $pageId): bool => $pageId !== $rootId));
    }
    if (!$pageIds) {
        return [];
    }

    $accessDepartments = normalizePageAccessDepartments(is_array($accessDepartments) ? $accessDepartments : [$accessDepartments]);
    $accessDepartment = legacyPageAccessDepartment($accessDepartments);
    $accessMemberIds = array_values(array_unique(array_filter(array_map('intval', $accessMemberIds), static fn(int $memberId): bool => $memberId > 0)));
    $placeholders = sqlPlaceholders($pageIds);
    $privateAssignments = $forcePrivate ? "status = 'private', published_at = NULL," : '';
    $visibilityAssignment = $forcePrivate
        ? 'visibility = ?'
        : "visibility = CASE WHEN status = 'private' OR visibility = 'private' THEN 'private' ELSE ? END";
    $statement = $pdo->prepare(<<<SQL
UPDATE pages
SET {$visibilityAssignment}, access_department = ?,
    {$privateAssignments}
    updated_by = ?, updated_at = CURRENT_TIMESTAMP
WHERE id IN ({$placeholders})
SQL);
    $statement->execute(array_merge([
        $visibility,
        $accessDepartment,
        $actorId,
    ], $pageIds));

    $deleteDepartments = $pdo->prepare("DELETE FROM page_access_departments WHERE page_id IN ({$placeholders})");
    $deleteDepartments->execute($pageIds);
    if ($accessDepartments !== []) {
        $grantDepartment = $pdo->prepare('INSERT INTO page_access_departments (page_id, department, granted_by) VALUES (?, ?, ?)');
        foreach ($pageIds as $pageId) {
            foreach ($accessDepartments as $department) {
                $grantDepartment->execute([$pageId, $department, $actorId]);
            }
        }
    }

    $delete = $pdo->prepare("DELETE FROM page_access_members WHERE page_id IN ({$placeholders})");
    $delete->execute($pageIds);
    if ($visibility === 'group' && $accessMemberIds) {
        $eligible = $pdo->prepare("SELECT id FROM pages WHERE id IN ({$placeholders}) AND visibility = 'group'");
        $eligible->execute($pageIds);
        $eligiblePageIds = array_map('intval', $eligible->fetchAll(PDO::FETCH_COLUMN));
        $grant = $pdo->prepare('INSERT INTO page_access_members (page_id, user_id, granted_by) VALUES (?, ?, ?)');
        foreach ($eligiblePageIds as $pageId) {
            foreach ($accessMemberIds as $memberId) {
                $grant->execute([$pageId, $memberId, $actorId]);
            }
        }
    }

    invalidateRagPageGenerations($pdo, $pageIds);

    foreach ($pageIds as $pageId) {
        audit($pdo, $actorId, 'access_inherited', 'page', $pageId, [
            'source_page_id' => $rootId,
            'visibility' => $visibility,
            'access_departments' => $accessDepartments,
            'forced_private' => $forcePrivate,
        ]);
    }
    return $pageIds;
}

/** @return array<int, int> */
function archivedAncestorPageIds(PDO $pdo, int $pageId): array
{
    $rows = $pdo->query('SELECT id, parent_id, archived_at FROM pages')->fetchAll();
    $pages = [];
    foreach ($rows as $row) {
        $pages[(int) $row['id']] = $row;
    }

    $result = [];
    $current = $pages[$pageId] ?? null;
    while ($current && $current['parent_id'] !== null) {
        $parentId = (int) $current['parent_id'];
        $parent = $pages[$parentId] ?? null;
        if (!$parent) {
            break;
        }
        if ($parent['archived_at'] !== null) {
            $result[] = $parentId;
        }
        $current = $parent;
    }
    return $result;
}

function sqlPlaceholders(array $values): string
{
    return implode(', ', array_fill(0, count($values), '?'));
}

function baseUrl(): string
{
    $configured = trim((string) getenv('OPENCONCEPT_APP_URL'));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $secure ? 'https' : 'http';
    $host = preg_replace('/[^a-zA-Z0-9.:[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $directory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    return rtrim($scheme . '://' . $host . ($directory === '/' ? '' : $directory), '/');
}

function passwordError(string $password): ?string
{
    $length = function_exists('mb_strlen') ? mb_strlen($password) : strlen($password);
    if ($length < 12) {
        return 'パスワードは12文字以上にしてください。';
    }
    $classes = 0;
    $classes += preg_match('/[a-z]/', $password) ? 1 : 0;
    $classes += preg_match('/[A-Z]/', $password) ? 1 : 0;
    $classes += preg_match('/[0-9]/', $password) ? 1 : 0;
    $classes += preg_match('/[^a-zA-Z0-9]/u', $password) ? 1 : 0;
    if ($classes < 3) {
        return '英大文字・英小文字・数字・記号のうち3種類以上を使用してください。';
    }
    return null;
}

function temporaryPassword(int $length = 16): string
{
    $groups = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%*-_'];
    $characters = $groups[0] . $groups[1] . $groups[2] . $groups[3];
    $password = '';
    foreach ($groups as $group) {
        $password .= $group[random_int(0, strlen($group) - 1)];
    }
    while (strlen($password) < $length) {
        $password .= $characters[random_int(0, strlen($characters) - 1)];
    }
    $array = str_split($password);
    for ($index = count($array) - 1; $index > 0; $index--) {
        $swap = random_int(0, $index);
        [$array[$index], $array[$swap]] = [$array[$swap], $array[$index]];
    }
    return implode('', $array);
}

function cleanText(string $value, int $max = 2000): string
{
    $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
    return utf8Slice($value, $max);
}

function utf8Slice(string $value, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    if (function_exists('iconv_substr')) {
        $result = iconv_substr($value, 0, $max, 'UTF-8');
        return $result === false ? '' : $result;
    }
    return substr($value, 0, $max);
}

function lowerText(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

/**
 * @return array<int, array<string, mixed>>
 */
function notificationsForUser(PDO $pdo, array $user, int $limit = 50): array
{
    return (new NotificationInbox($pdo))->forUser($user, $limit);
}

function createNotification(PDO $pdo, int $userId, string $type, string $message, ?int $pageId): int
{
    $statement = $pdo->prepare('INSERT INTO notifications (user_id, type, message, page_id) VALUES (?, ?, ?, ?)');
    $statement->execute([
        $userId,
        cleanText($type, 40),
        cleanText($message, 500),
        $pageId,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Keeps repeated autosave notifications useful without flooding the inbox.
 */
function createOrRefreshNotification(PDO $pdo, int $userId, string $type, string $message, ?int $pageId): int
{
    $existingId = (new NotificationInbox($pdo))->refreshCandidate($userId, $type, $pageId);
    if ($existingId > 0) {
        $update = $pdo->prepare('UPDATE notifications SET message = ?, created_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?');
        $update->execute([cleanText($message, 500), $existingId, $userId]);
        return $existingId;
    }
    return createNotification($pdo, $userId, $type, $message, $pageId);
}

/**
 * Persist a deduplicated system-level notice for every active application
 * administrator when static publication cannot establish private permissions.
 */
function notifyStaticPublicationPermissionFailure(PDO $pdo, Throwable $exception): bool
{
    if (!$exception instanceof StaticPublicationException
        || $exception->publicCode !== 'publication_permissions_unsafe') {
        return false;
    }
    if ($pdo->inTransaction()) {
        error_log('OpenConcept could not notify administrators while a database transaction was active.');
        return false;
    }
    $message = trim((string) $exception->adminMessage);
    if ($message === '') {
        $message = '静的Web公開を安全のため停止しました。秘密鍵と内部保存領域の所有者・アクセス権限を確認してください。';
    }
    try {
        $administratorIds = $pdo->query(
            "SELECT id FROM users WHERE active = 1 AND role = 'admin' ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($administratorIds === []) {
            error_log('OpenConcept static publication permission failure: no active administrator could be notified.');
            return false;
        }
        foreach ($administratorIds as $administratorId) {
            createOrRefreshNotification(
                $pdo,
                (int) $administratorId,
                'static_publication_permission',
                $message,
                null
            );
        }
        return true;
    } catch (Throwable $notificationException) {
        error_log(
            'OpenConcept static publication permission notification failed: '
            . get_class($notificationException)
        );
        return false;
    }
}

/**
 * Resolve exact @display-name occurrences. Longer names win when names overlap.
 *
 * @param array<int, array<string, mixed>> $members
 * @return array<int, int>
 */
function mentionedUserIds(string $body, array $members): array
{
    $matches = [];
    foreach ($members as $member) {
        $name = trim((string) ($member['name'] ?? ''));
        $userId = (int) ($member['id'] ?? 0);
        if ($name === '' || $userId < 1) {
            continue;
        }
        $pattern = '/(?<![\p{L}\p{N}_@])@' . preg_quote($name, '/') . '(?![\p{L}\p{N}_])/u';
        $found = [];
        if (preg_match_all($pattern, $body, $found, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($found[0] ?? [] as [$value, $offset]) {
                $matches[] = [
                    'user_id' => $userId,
                    'start' => (int) $offset,
                    'end' => (int) $offset + strlen((string) $value),
                ];
            }
        }
    }
    usort($matches, static function (array $left, array $right): int {
        return ($left['start'] <=> $right['start']) ?: (($right['end'] - $right['start']) <=> ($left['end'] - $left['start']));
    });

    $ids = [];
    $occupiedUntil = -1;
    foreach ($matches as $match) {
        if ($match['start'] < $occupiedUntil) {
            continue;
        }
        $ids[$match['user_id']] = $match['user_id'];
        $occupiedUntil = $match['end'];
    }
    return array_values($ids);
}

/**
 * Creates at most one notification per recipient for a newly posted comment.
 * Mention takes priority over reply, which takes priority over page-owner notice.
 *
 * @return array{count:int, mentioned_user_ids:array<int, int>}
 */
function notifyCommentParticipants(PDO $pdo, array $actor, array $page, string $body, ?int $parentId): array
{
    $members = $pdo->query("SELECT id, name, email, avatar_color, role, department FROM users WHERE active = 1 AND role <> 'system'")->fetchAll();
    $membersById = [];
    foreach ($members as $member) {
        $member['id'] = (int) $member['id'];
        $membersById[$member['id']] = $member;
    }

    $actorId = (int) $actor['id'];
    $pageId = (int) $page['id'];
    $mentionedIds = mentionedUserIds($body, $members);
    $recipients = [];
    foreach ($mentionedIds as $mentionedId) {
        $member = $membersById[$mentionedId] ?? null;
        if (!$member || $mentionedId === $actorId || !canViewPage($pdo, $member, $page)) {
            continue;
        }
        $recipients[$mentionedId] = 'mention';
    }

    if ($parentId !== null) {
        $parentStatement = $pdo->prepare('SELECT user_id FROM comments WHERE id = ? AND page_id = ?');
        $parentStatement->execute([$parentId, $pageId]);
        $parentAuthorId = (int) ($parentStatement->fetchColumn() ?: 0);
        $parentAuthor = $membersById[$parentAuthorId] ?? null;
        if ($parentAuthor && $parentAuthorId !== $actorId && canViewPage($pdo, $parentAuthor, $page)) {
            $recipients[$parentAuthorId] ??= 'reply';
        }
    }

    $pageAuthorId = (int) ($page['author_id'] ?? 0);
    $pageAuthor = $membersById[$pageAuthorId] ?? null;
    if ($pageAuthor && $pageAuthorId !== $actorId && canViewPage($pdo, $pageAuthor, $page)) {
        $recipients[$pageAuthorId] ??= 'comment';
    }

    $actorName = cleanText((string) ($actor['name'] ?? ''), 120) ?: 'メンバー';
    $pageTitle = cleanText((string) ($page['title'] ?? ''), 240) ?: '無題';
    foreach ($recipients as $recipientId => $type) {
        $message = match ($type) {
            'mention' => "{$actorName}さんが「{$pageTitle}」であなたをメンションしました",
            'reply' => "{$actorName}さんが「{$pageTitle}」のコメントに返信しました",
            default => "「{$pageTitle}」に{$actorName}さんから新しいコメントがあります",
        };
        createNotification($pdo, (int) $recipientId, $type, $message, $pageId);
    }

    return ['count' => count($recipients), 'mentioned_user_ids' => array_values(array_map('intval', array_keys(array_filter($recipients, static fn(string $type): bool => $type === 'mention'))))];
}

function sanitizeHtml(string $html): string
{
    return SafeHtml::sanitize($html);
}

function sanitizeCodeContent(string $html): string
{
    $html = preg_replace('/<br\s*\/?\s*>/iu', "\n", $html) ?? $html;
    $html = preg_replace('/<\/(?:div|p|li|pre|blockquote)\s*>/iu', "\n", $html) ?? $html;
    $html = preg_replace('/<(?:div|p|li|pre|blockquote)(?:\s[^>]*)?>/iu', '', $html) ?? $html;
    $text = htmlspecialchars_decode(strip_tags($html), ENT_QUOTES);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/\n[ \t]*\n(?:[ \t]*\n)+/u', "\n\n", $text) ?? $text;
    return utf8Slice(trim($text, "\n"), 20000);
}

function sanitizeBlocks(mixed $blocks): array
{
    if (!is_array($blocks)) {
        return [];
    }
    $allowed = ['paragraph', 'lead', 'heading1', 'heading2', 'heading3', 'bullet', 'number', 'todo', 'quote', 'code', 'divider', 'callout', 'image', 'file', 'table'];
    $result = [];
    foreach (array_slice($blocks, 0, 500) as $block) {
        if (!is_array($block)) {
            continue;
        }
        $type = in_array($block['type'] ?? '', $allowed, true) ? $block['type'] : 'paragraph';
        $item = [
            'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($block['id'] ?? 'b-' . bin2hex(random_bytes(5)))),
            'type' => $type,
            'content' => in_array($type, ['file', 'table'], true) ? '' : ($type === 'code' ? sanitizeCodeContent((string) ($block['content'] ?? '')) : sanitizeHtml((string) ($block['content'] ?? ''))),
            ...($type === 'todo' ? ['checked' => !empty($block['checked'])] : []),
            ...($type === 'callout' ? ['emoji' => cleanText((string) ($block['emoji'] ?? '💡'), 4)] : []),
        ];
        if (in_array($type, ['image', 'file'], true)) {
            $category = in_array($block['file_category'] ?? '', ['pdf', 'excel', 'word', 'markdown', 'text', 'image'], true)
                ? $block['file_category']
                : '';
            if ($type === 'image' && $category !== 'image') {
                $category = '';
            }
            $item += [
                'file_id' => max(0, (int) ($block['file_id'] ?? 0)),
                'file_name' => cleanText((string) ($block['file_name'] ?? ''), 255),
                'file_category' => $category,
                'file_size' => max(0, (int) ($block['file_size'] ?? 0)),
            ];
        }
        if ($type === 'table') {
            $rawRows = array_slice(is_array($block['table_rows'] ?? null) ? $block['table_rows'] : [], 0, 50);
            if (!$rawRows) {
                $rawRows = array_fill(0, 3, array_fill(0, 3, ['content' => '', 'align' => 'left']));
            }
            $columnCount = 1;
            foreach ($rawRows as $rawRow) {
                if (is_array($rawRow)) {
                    $columnCount = max($columnCount, min(12, count($rawRow)));
                }
            }
            $tableRows = [];
            $allowedBackgrounds = ['default', 'gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];
            foreach ($rawRows as $rawRow) {
                $row = [];
                $rawRow = is_array($rawRow) ? array_values($rawRow) : [];
                for ($column = 0; $column < $columnCount; $column++) {
                    $rawCell = $rawRow[$column] ?? [];
                    $cellContent = is_array($rawCell) ? (string) ($rawCell['content'] ?? '') : (string) $rawCell;
                    $cellAlign = is_array($rawCell) && in_array($rawCell['align'] ?? '', ['left', 'center', 'right'], true)
                        ? $rawCell['align']
                        : 'left';
                    $cellBackground = is_array($rawCell) && in_array($rawCell['background'] ?? '', $allowedBackgrounds, true)
                        ? $rawCell['background']
                        : 'default';
                    $row[] = ['content' => sanitizeHtml($cellContent), 'align' => $cellAlign, 'background' => $cellBackground];
                }
                $tableRows[] = $row;
            }
            $rawWidths = array_values(array_slice(is_array($block['table_widths'] ?? null) ? $block['table_widths'] : [], 0, $columnCount));
            if (count($rawWidths) !== $columnCount) {
                $rawWidths = array_fill(0, $columnCount, 100 / $columnCount);
            }
            $tableWidths = array_map(static fn($width): float => max(8.0, min(84.0, (float) $width)), $rawWidths);
            $widthTotal = array_sum($tableWidths) ?: 100.0;
            $tableWidths = array_map(static fn(float $width): float => round($width * 100 / $widthTotal, 4), $tableWidths);
            $tableWidths[$columnCount - 1] = round($tableWidths[$columnCount - 1] + 100 - array_sum($tableWidths), 4);
            $item += ['table_rows' => $tableRows, 'table_widths' => $tableWidths];
        }
        $result[] = $item;
    }
    return $result;
}

/** @param array<int, array<string, mixed>> $blocks */
function blocksPlainText(array $blocks): string
{
    return implode("\n", array_map(static function (array $block): string {
        if (($block['type'] ?? '') === 'image') {
            return trim(strip_tags((string) ($block['content'] ?? '')) . ' ' . (string) ($block['file_name'] ?? ''));
        }
        if (($block['type'] ?? '') === 'file') {
            return (string) ($block['file_name'] ?? '');
        }
        if (($block['type'] ?? '') === 'table') {
            return implode("\n", array_map(
                static fn(array $row): string => implode("\t", array_map(
                    static fn(array $cell): string => strip_tags((string) ($cell['content'] ?? '')),
                    $row
                )),
                $block['table_rows'] ?? []
            ));
        }
        return strip_tags((string) ($block['content'] ?? ''));
    }, $blocks));
}

/** @return array<string, mixed> */
function queueRagPage(PDO $pdo, int $pageId, bool $force = false): array
{
    try {
        return (new RagPipeline($pdo))->queuePage($pageId, $force);
    } catch (Throwable $exception) {
        error_log('OpenConcept RAG queue error for page ' . $pageId . ': ' . $exception->getMessage());
        return ['state' => 'unavailable'];
    }
}

function normalizePageTag(string $tag): string
{
    if (class_exists('Normalizer')) {
        $tag = Normalizer::normalize($tag, Normalizer::FORM_KC) ?: $tag;
    }
    $tag = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
    return preg_replace('/[\s\p{P}\p{S}]+/u', '', trim($tag)) ?? '';
}

/** @param array<int, mixed> $tags @return array<int, string> */
function uniquePageTags(array $tags, int $limit = 12): array
{
    $result = [];
    $seen = [];
    foreach (array_slice($tags, 0, max($limit * 2, $limit)) as $tag) {
        $name = preg_replace('/^#+/u', '', cleanText((string) $tag, 40)) ?? '';
        $normalized = normalizePageTag($name);
        if ($name === '' || $normalized === '' || isset($seen[$normalized])) {
            continue;
        }
        $result[] = $name;
        $seen[$normalized] = true;
        if (count($result) >= $limit) {
            break;
        }
    }
    return $result;
}

/** @return array<int, string> */
function activeGeneratedPageTags(PDO $pdo, int $pageId): array
{
    $statement = $pdo->prepare(<<<'SQL'
SELECT g.tag
FROM rag_generated_tags g
JOIN rag_source_documents d
  ON d.page_id = g.page_id
 AND d.source_hash = g.source_hash
 AND d.is_current = 1
 AND d.source_schema_version = ?
WHERE g.page_id = ? AND g.prompt_version = ? AND g.is_active = 1
ORDER BY g.relevance DESC, g.id
SQL);
    $statement->execute([RagPipeline::SOURCE_SCHEMA_VERSION, $pageId, RagPipeline::PROMPT_VERSION]);
    return uniquePageTags($statement->fetchAll(PDO::FETCH_COLUMN), 8);
}

/** @param array<string, mixed> $page @return array<int, string> */
function visiblePageTags(PDO $pdo, int $pageId, array $page): array
{
    $storedManualTags = json_decode((string) ($page['manual_tags_json'] ?? ''), true);
    if (!is_array($storedManualTags)) {
        // A database upgraded from the pre-manual-tag schema was backfilled
        // from tags_json. Keep this fallback only for incomplete old rows.
        $storedManualTags = json_decode((string) ($page['tags_json'] ?? '[]'), true);
    }
    $manualTags = uniquePageTags(is_array($storedManualTags) ? $storedManualTags : [], 12);
    return mergePageTags($manualTags, activeGeneratedPageTags($pdo, $pageId));
}

/**
 * Strong identity for the live page material sent to an AI model. ACL fields
 * are intentionally excluded because readers re-authorize them separately.
 * Manual tags are included because they are user-authored prompt input; the
 * mixed legacy/generated tags_json column is not authoritative.
 *
 * @param array<string, mixed> $page
 */
function aiPageContentFingerprint(PDO $pdo, array $page, bool $includeVisibleTags = true): string
{
    $pageId = (int) ($page['page_id'] ?? $page['id'] ?? 0);
    $required = ['blocks_json', 'plain_text'];
    foreach ($required as $field) {
        if (!array_key_exists($field, $page) && $pageId > 0) {
            $statement = $pdo->prepare('SELECT title, category, manual_tags_json, blocks_json, plain_text FROM pages WHERE id = ?');
            $statement->execute([$pageId]);
            $stored = $statement->fetch();
            if (is_array($stored)) {
                $page = array_merge($page, $stored);
            }
            break;
        }
    }
    $manualTags = json_decode((string) ($page['manual_tags_json'] ?? ''), true);
    if (!is_array($manualTags)) {
        $manualTags = json_decode((string) ($page['tags_json'] ?? '[]'), true);
    }
    $payload = [
        'title' => (string) ($page['page_title'] ?? $page['title'] ?? ''),
        'category' => (string) ($page['category'] ?? ''),
        'manual_tags' => uniquePageTags(is_array($manualTags) ? $manualTags : [], 12),
        'blocks_json' => (string) ($page['blocks_json'] ?? ''),
        'plain_text' => (string) ($page['plain_text'] ?? ''),
    ];
    if ($includeVisibleTags) {
        $payload['visible_tags'] = isset($page['visible_tags']) && is_array($page['visible_tags'])
            ? uniquePageTags($page['visible_tags'], 12)
            : visiblePageTags($pdo, $pageId, $page);
    }
    return hash('sha256', json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ));
}

/** @param array<int, mixed> $manualTags @param array<int, mixed> $generatedTags @return array<int, string> */
function mergePageTags(array $manualTags, array $generatedTags): array
{
    $manual = uniquePageTags($manualTags, 12);
    return uniquePageTags([...$manual, ...array_slice($generatedTags, 0, 8)], 12);
}

/** @return array<string, array<int, string>> */
function profileIconChoices(): array
{
    return [
        'animals' => ['🐶', '🐱', '🐰', '🐻', '🦊', '🐼', '🐨', '🐯', '🐸', '🐧', '🦁', '🐙'],
        'fruits' => ['🍎', '🍊', '🍋', '🍇', '🍓', '🍒', '🍑', '🍍', '🥝', '🍉', '🥭', '🍐'],
        'vegetables' => ['🥕', '🌽', '🍅', '🥦', '🥬', '🫑', '🍆', '🥔', '🍄', '🧅', '🫛', '🥒'],
        'vehicles' => ['🚗', '🚕', '🚌', '🚎', '🚓', '🚑', '🚒', '🚚', '🚲', '✈️', '🚀', '🚁', '🚢', '🚂'],
    ];
}

/** @return array<int, string> */
function allowedProfileIcons(): array
{
    return array_values(array_unique(array_merge(...array_values(profileIconChoices()))));
}

function profilePhotoLimitBytes(): int
{
    return min(5, uploadLimitMegabytes()) * 1024 * 1024;
}

/** @return array{extension: string, mime: string, width: int, height: int}|null */
function inspectProfilePhoto(string $temporaryPath, string $originalName): ?array
{
    if (!is_file($temporaryPath)) {
        return null;
    }
    $extension = lowerText((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $extension = $extension === 'jpeg' ? 'jpg' : $extension;
    $mimes = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];
    if (!isset($mimes[$extension])) {
        return null;
    }
    if (class_exists('finfo')) {
        $detector = new finfo(FILEINFO_MIME_TYPE);
        if ((string) $detector->file($temporaryPath) !== $mimes[$extension]) {
            return null;
        }
    }
    $image = @getimagesize($temporaryPath);
    $width = (int) ($image[0] ?? 0);
    $height = (int) ($image[1] ?? 0);
    if ($image === false || (string) ($image['mime'] ?? '') !== $mimes[$extension] || $width < 1 || $height < 1) {
        return null;
    }
    if ($width > 6000 || $height > 6000 || $width * $height > 20000000) {
        return null;
    }
    return ['extension' => $extension, 'mime' => $mimes[$extension], 'width' => $width, 'height' => $height];
}

function validStoredProfilePhoto(int $userId, string $storedName): bool
{
    return $userId > 0
        && basename($storedName) === $storedName
        && preg_match('/\Aavatar-' . preg_quote((string) $userId, '/') . '-[a-f0-9]{48}\.(?:png|jpg|webp)\z/D', $storedName) === 1;
}

/** @return array<string, mixed>|null */
function updateUserProfile(PDO $pdo, int $userId, string $department, string $avatarKind, string $avatarValue): ?array
{
    if ($userId < 1 || !in_array($avatarKind, ['initials', 'emoji', 'photo'], true)) {
        return null;
    }
    if ($avatarKind === 'initials') {
        $avatarValue = '';
    } elseif ($avatarKind === 'emoji' && !in_array($avatarValue, allowedProfileIcons(), true)) {
        return null;
    } elseif ($avatarKind === 'photo' && !validStoredProfilePhoto($userId, $avatarValue)) {
        return null;
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        beginPageWriteTransaction($pdo);
    }

    try {
        $sql = <<<'SQL'
SELECT id, name, email, avatar_color, avatar_kind, avatar_value, role, department, must_change_password, invited_at, last_login_at
FROM users
WHERE id = ? AND active = 1 AND role NOT IN ('system', 'suspended')
SQL;
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $current = $pdo->prepare($sql);
        $current->execute([$userId]);
        $user = $current->fetch();
        if (!$user) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }

        // Department membership affects access. Only a live, locked system
        // administrator may change their own department through their profile.
        $previousDepartment = (string) $user['department'];
        if ((string) $user['role'] === 'admin') {
            $department = cleanText($department, 120);
            if ($department === '') {
                throw new RuntimeException('部署名を入力してください。', 422);
            }
            $update = $pdo->prepare('UPDATE users SET department = ?, avatar_kind = ?, avatar_value = ? WHERE id = ?');
            $update->execute([$department, $avatarKind, $avatarValue, $userId]);
        } else {
            $department = $previousDepartment;
            $update = $pdo->prepare('UPDATE users SET avatar_kind = ?, avatar_value = ? WHERE id = ?');
            $update->execute([$avatarKind, $avatarValue, $userId]);
        }
        audit($pdo, $userId, 'profile_updated', 'user', $userId, [
            'previous_department' => $previousDepartment,
            'department' => $department,
            'previous_avatar_kind' => (string) $user['avatar_kind'],
            'avatar_kind' => $avatarKind,
        ]);

        $user['id'] = $userId;
        $user['department'] = $department;
        $user['avatar_kind'] = $avatarKind;
        $user['avatar_value'] = $avatarValue;
        $user['must_change_password'] = (bool) $user['must_change_password'];
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $user;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function deleteUnusedProfilePhoto(PDO $pdo, string $storedName): void
{
    if ($storedName === '' || basename($storedName) !== $storedName || !preg_match('/\Aavatar-\d+-[a-f0-9]{48}\.(?:png|jpg|webp)\z/D', $storedName)) {
        return;
    }
    $reference = $pdo->prepare("SELECT COUNT(*) FROM users WHERE avatar_kind = 'photo' AND avatar_value = ?");
    $reference->execute([$storedName]);
    if ((int) $reference->fetchColumn() > 0) {
        return;
    }
    $path = uploadStoragePath() . DIRECTORY_SEPARATOR . $storedName;
    if (is_file($path) && !unlink($path)) {
        error_log('OpenConcept profile photo cleanup failed: ' . $path);
    }
}

/** @return array<string, array{category: string, mimes: array<int, string>}> */
function allowedUploadTypes(): array
{
    return [
        'pdf' => ['category' => 'pdf', 'mimes' => ['application/pdf']],
        'xls' => ['category' => 'excel', 'mimes' => ['application/vnd.ms-excel', 'application/x-cdf', 'application/octet-stream']],
        'xlsx' => ['category' => 'excel', 'mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']],
        'doc' => ['category' => 'word', 'mimes' => ['application/msword', 'application/x-cdf', 'application/octet-stream']],
        'docx' => ['category' => 'word', 'mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']],
        'md' => ['category' => 'markdown', 'mimes' => ['text/plain', 'text/markdown', 'text/x-markdown', 'application/octet-stream']],
        'markdown' => ['category' => 'markdown', 'mimes' => ['text/plain', 'text/markdown', 'text/x-markdown', 'application/octet-stream']],
        'txt' => ['category' => 'text', 'mimes' => ['text/plain', 'application/octet-stream']],
        'png' => ['category' => 'image', 'mimes' => ['image/png']],
        'jpg' => ['category' => 'image', 'mimes' => ['image/jpeg']],
        'jpeg' => ['category' => 'image', 'mimes' => ['image/jpeg']],
        'gif' => ['category' => 'image', 'mimes' => ['image/gif']],
        'webp' => ['category' => 'image', 'mimes' => ['image/webp']],
    ];
}

function uploadStoragePath(): string
{
    $configured = trim((string) getenv('OPENCONCEPT_UPLOAD_DIR'));
    return $configured !== '' ? rtrim($configured, "/\\") : dirname(__DIR__) . '/storage/uploads';
}

/**
 * Finds the pages that actually contain each uploaded file in a block.
 * The caller is responsible for passing only pages visible to the current user.
 *
 * @param array<int, array<string, mixed>> $pages
 * @return array<int, array<int, array{page_id: int, page_title: string}>>
 */
function fileReferencesById(array $pages): array
{
    $references = [];
    foreach ($pages as $page) {
        $pageId = (int) ($page['id'] ?? 0);
        if ($pageId < 1) {
            continue;
        }
        $blocks = $page['blocks'] ?? json_decode((string) ($page['blocks_json'] ?? ''), true);
        if (!is_array($blocks)) {
            continue;
        }
        $fileIds = [];
        foreach ($blocks as $block) {
            $fileId = is_array($block) ? (int) ($block['file_id'] ?? 0) : 0;
            if ($fileId > 0) {
                $fileIds[$fileId] = true;
            }
        }
        foreach (array_keys($fileIds) as $fileId) {
            $references[$fileId][] = [
                'page_id' => $pageId,
                'page_title' => cleanText((string) ($page['title'] ?? '無題'), 240) ?: '無題',
            ];
        }
    }
    return $references;
}

/** @param array<int, array<string, mixed>> $blocks @return array{blocks: array<int, array<string, mixed>>, changed: bool} */
function removeFileReferencesFromBlocks(array $blocks, int $fileId): array
{
    $changed = false;
    foreach ($blocks as &$block) {
        if ((int) ($block['file_id'] ?? 0) !== $fileId) {
            continue;
        }
        unset($block['file_id'], $block['file_name'], $block['file_category'], $block['file_size'], $block['uploading']);
        $changed = true;
    }
    unset($block);
    return ['blocks' => $blocks, 'changed' => $changed];
}

/** @return array{id: int, original_name: string, page_id: int|null} */
function deleteFileRecord(
    PDO $pdo,
    array $user,
    int $fileId,
    string $expectedPasswordFingerprint,
    ?PluginFileService $lifecycle = null
): array
{
    $statement = $pdo->prepare('SELECT * FROM files WHERE id = ?');
    $statement->execute([$fileId]);
    $file = $statement->fetch();
    if (!$file) {
        throw new RuntimeException('ファイルが見つかりません。', 404);
    }

    $storedName = (string) $file['stored_name'];
    if ($storedName === '' || basename($storedName) !== $storedName) {
        throw new RuntimeException('保存ファイル名が正しくありません。', 500);
    }
    $path = uploadStoragePath() . DIRECTORY_SEPARATOR . $storedName;
    $quarantinePath = null;
    $pageId = $file['page_id'] === null ? null : (int) $file['page_id'];
    try {
        beginPageWriteTransaction($pdo);
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $fileSql = 'SELECT * FROM files WHERE id = ?';
        if ($driver !== 'sqlite') {
            $fileSql .= ' FOR UPDATE';
        }

        // All file writers use actor -> page hierarchy -> file ordering. This
        // matches page lifecycle writers and prevents page/file lock inversion.
        $liveUser = lockedAuthenticatedApplicationUserForWrite(
            $pdo,
            $user,
            $expectedPasswordFingerprint
        );
        if ($liveUser === null) {
            throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
        }

        $page = null;
        if ($pageId !== null) {
            $lockedHierarchy = lockedPageHierarchiesForUpdate($pdo, [$pageId]);
            $page = $lockedHierarchy['rows'][$pageId] ?? null;
            if ($lockedHierarchy['reason'] !== null || !is_array($page)) {
                throw new RuntimeException('ページが別の操作で更新されました。', 409);
            }
        }

        $lockedFile = $pdo->prepare($fileSql);
        $lockedFile->execute([$fileId]);
        $liveFile = $lockedFile->fetch();
        $livePageId = is_array($liveFile) && $liveFile['page_id'] !== null
            ? (int) $liveFile['page_id']
            : null;
        if (!is_array($liveFile)
            || !hash_equals($storedName, (string) $liveFile['stored_name'])
            || $livePageId !== $pageId) {
            throw new RuntimeException('ファイルが別の操作で更新されました。', 409);
        }
        $user = $liveUser;
        $file = $liveFile;
        if (!canDeleteFile($pdo, $user, $file)) {
            throw new RuntimeException('このファイルを削除する権限がありません。', 403);
        }

        $extensionSource = $lifecycle?->fileSource($fileId, $user);
        $extensionEvents = is_array($extensionSource)
            ? [$lifecycle->journalEvent('source.deleted', $extensionSource, ['actor_user_id' => (int) $user['id']])]
            : [];

        if (is_file($path)) {
            $quarantinePath = $path . '.deleting-' . bin2hex(random_bytes(6));
            if (!rename($path, $quarantinePath)) {
                throw new RuntimeException('保存ファイルを削除準備できませんでした。', 500);
            }
        }

        if ($pageId !== null && is_array($page)) {
            $blocks = json_decode((string) $page['blocks_json'], true) ?: [];
            $cleaned = removeFileReferencesFromBlocks($blocks, $fileId);
            if ($cleaned['changed']) {
                $meta = [
                    'status' => $page['status'],
                    'category' => $page['category'],
                    'tags' => json_decode((string) $page['tags_json'], true) ?: [],
                    'manual_tags' => json_decode((string) ($page['manual_tags_json'] ?? '[]'), true) ?: [],
                    'visibility' => $page['visibility'],
                    'access_department' => (string) ($page['access_department'] ?? ''),
                    'access_departments' => pageAccessDepartments($pdo, $pageId, $page),
                    'access_member_ids' => pageAccessMemberIds($pdo, $pageId),
                ];
                $revision = $pdo->prepare('INSERT INTO revisions (page_id, title, icon, blocks_json, meta_json, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                $revision->execute([$pageId, $page['title'], $page['icon'], $page['blocks_json'], json_encode($meta, JSON_UNESCAPED_UNICODE), (int) $user['id']]);
                $blocksJson = json_encode($cleaned['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $update = $pdo->prepare("UPDATE pages SET blocks_json = ?, plain_text = ?, content_revision = content_revision + 1, translation_status = CASE WHEN source_page_id IS NULL THEN translation_status ELSE 'human_edited' END, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $update->execute([$blocksJson, utf8Slice(blocksPlainText($cleaned['blocks']), 100000), (int) $user['id'], $pageId]);
                invalidateRagPageGenerations($pdo, [$pageId]);
            }
        }
        $pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$fileId]);
        audit($pdo, (int) $user['id'], 'file_deleted', 'file', $fileId, [
            'name' => (string) $file['original_name'],
            'page_id' => $pageId,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($quarantinePath !== null && is_file($quarantinePath)) {
            rename($quarantinePath, $path);
        }
        throw $exception;
    }

    if ($quarantinePath !== null && is_file($quarantinePath) && !unlink($quarantinePath)) {
        error_log('OpenConcept deleted file cleanup failed: ' . $quarantinePath);
    }
    $lifecycle?->dispatchEvents($extensionEvents);
    if ($pageId !== null) {
        queueRagPage($pdo, $pageId);
    }
    return [
        'id' => $fileId,
        'original_name' => (string) $file['original_name'],
        'page_id' => $pageId,
    ];
}

function iniSizeBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $unit = lowerText(substr($value, -1));
    $number = (float) $value;
    return (int) round($number * match ($unit) {
        'g' => 1024 ** 3,
        'm' => 1024 ** 2,
        'k' => 1024,
        default => 1,
    });
}

function uploadLimitMegabytes(): int
{
    $configured = max(1, min(15, (int) (getenv('OPENCONCEPT_MAX_UPLOAD_MB') ?: 15))) * 1024 * 1024;
    $limits = [$configured];
    foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
        $bytes = iniSizeBytes((string) ini_get($setting));
        if ($bytes > 0) {
            $limits[] = $bytes;
        }
    }
    return max(1, (int) floor(min($limits) / 1024 / 1024));
}

/** @return array{extension: string, category: string, mime: string}|null */
function inspectUploadedFile(string $temporaryPath, string $originalName): ?array
{
    $extension = lowerText((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $rules = allowedUploadTypes();
    if (!isset($rules[$extension]) || !is_file($temporaryPath)) {
        return null;
    }

    $mime = $rules[$extension]['mimes'][0];
    if (class_exists('finfo')) {
        $detector = new finfo(FILEINFO_MIME_TYPE);
        $detected = (string) $detector->file($temporaryPath);
        if ($detected !== '' && !in_array($detected, $rules[$extension]['mimes'], true)) {
            return null;
        }
    }

    $category = $rules[$extension]['category'];
    if ($category === 'image') {
        $image = @getimagesize($temporaryPath);
        if ($image === false || !in_array((string) ($image['mime'] ?? ''), $rules[$extension]['mimes'], true)) {
            return null;
        }
        $mime = (string) $image['mime'];
    }
    if ($category === 'pdf') {
        $handle = fopen($temporaryPath, 'rb');
        $signature = $handle ? fread($handle, 5) : false;
        if ($handle) {
            fclose($handle);
        }
        if ($signature !== '%PDF-') {
            return null;
        }
    }
    if (in_array($category, ['markdown', 'text'], true)) {
        $sample = file_get_contents($temporaryPath, false, null, 0, 8192);
        if ($sample === false || str_contains($sample, "\0")) {
            return null;
        }
    }
    if (in_array($extension, ['xls', 'doc'], true)) {
        $handle = fopen($temporaryPath, 'rb');
        $signature = $handle ? fread($handle, 8) : false;
        if ($handle) {
            fclose($handle);
        }
        if ($signature !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return null;
        }
    }
    if (in_array($extension, ['xlsx', 'docx'], true)) {
        $handle = fopen($temporaryPath, 'rb');
        $signature = $handle ? fread($handle, 4) : false;
        if ($handle) {
            fclose($handle);
        }
        if (!in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            return null;
        }
    }
    if (in_array($extension, ['xlsx', 'docx'], true) && class_exists('ZipArchive')) {
        $archive = new ZipArchive();
        if ($archive->open($temporaryPath) !== true) {
            return null;
        }
        $requiredPrefix = $extension === 'xlsx' ? 'xl/' : 'word/';
        $valid = $archive->locateName('[Content_Types].xml') !== false;
        if ($valid) {
            $valid = false;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                if (str_starts_with((string) $archive->getNameIndex($index), $requiredPrefix)) {
                    $valid = true;
                    break;
                }
            }
        }
        $archive->close();
        if (!$valid) {
            return null;
        }
    }

    return ['extension' => $extension, 'category' => $category, 'mime' => $mime];
}

function audit(PDO $pdo, int $userId, string $action, string $type, int $subjectId, array $detail = []): void
{
    $statement = $pdo->prepare('INSERT INTO audit_logs (user_id, action, subject_type, subject_id, detail_json) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$userId, $action, $type, $subjectId, json_encode($detail, JSON_UNESCAPED_UNICODE)]);
}
