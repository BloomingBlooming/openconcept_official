<?php

declare(strict_types=1);

require_once __DIR__ . '/PrefixedPDO.php';
require_once __DIR__ . '/DatabaseAdapterException.php';
require_once __DIR__ . '/DatabaseCapabilitiesInterface.php';
require_once __DIR__ . '/DatabaseCapabilityProviderInterface.php';
require_once __DIR__ . '/DatabaseSetupCapabilityProvisionerInterface.php';
require_once __DIR__ . '/DatabaseCapabilities.php';
require_once __DIR__ . '/LogicalDatabaseSchema.php';
require_once __DIR__ . '/DatabaseAdapterInterface.php';
require_once __DIR__ . '/SqliteDatabaseAdapter.php';
require_once __DIR__ . '/DatabaseAdapterRegistry.php';
require_once __DIR__ . '/DatabaseAdapterStateStore.php';
require_once __DIR__ . '/DatabasePrivilegeManager.php';
require_once __DIR__ . '/DatabaseSchemaInspector.php';
require_once __DIR__ . '/DatabaseMaintenanceMode.php';
require_once __DIR__ . '/DatabaseSelectionGuard.php';
require_once __DIR__ . '/DatabaseWorkspaceOwnership.php';
require_once __DIR__ . '/CoreSchemaCatalog.php';
require_once __DIR__ . '/CoreSchemaException.php';
require_once __DIR__ . '/CoreSchemaRuntime.php';
require_once __DIR__ . '/CoreSchemaVerifier.php';
require_once __DIR__ . '/PluginUpdateService.php';

final class Database
{
    private PDO $pdo;
    private string $backendId = 'sqlite';
    private bool $manualExternalBackend = false;
    /** @var array<string, mixed> */
    private array $connectionInfo = [];
    private DatabaseAdapterRegistry $adapterRegistry;
    private DatabaseAdapterStateStore $adapterStateStore;
    private DatabasePrivilegeManager $privilegeManager;
    private string $storagePath;
    private DatabaseMaintenanceMode $maintenanceMode;
    private DatabaseSelectionGuard $selectionGuard;

    /** @var resource|null */
    private mixed $maintenanceLease = null;

    /** @var resource|null */
    private mixed $selectionLease = null;

    public function __construct(string $storagePath)
    {
        $this->storagePath = rtrim($storagePath, "/\\");
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0775, true);
        }

        // This lease is acquired before adapter state is read and retained for
        // the Database lifetime. Adapter cutover upgrades this exact handle,
        // draining every process that could still hold an old-source PDO.
        $this->maintenanceMode = new DatabaseMaintenanceMode($this->storagePath);
        $this->maintenanceLease = $this->maintenanceMode->acquireShared();

        try {
        $applicationRoot = dirname(__DIR__);
        // A prepared journal can only survive here after the updating writer
        // relinquished EX. Every new Database passes recovery before loading
        // adapter PHP; the per-plugin lock serializes concurrent recovery.
        PluginUpdateService::recoverInterrupted($applicationRoot, $this->storagePath);
        $this->adapterRegistry = new DatabaseAdapterRegistry($applicationRoot . DIRECTORY_SEPARATOR . 'plugins');
        $this->adapterStateStore = new DatabaseAdapterStateStore($this->storagePath);
        $this->privilegeManager = new DatabasePrivilegeManager($this->storagePath);
        $runtimeState = $this->adapterStateStore->load();
        $openConceptDsn = getenv('OPENCONCEPT_DSN');
        $legacyDsn = getenv('MOKU_DSN');
        $dsn = $openConceptDsn ?: $legacyDsn ?: '';
        $dsnVariable = $openConceptDsn ? 'OPENCONCEPT_DSN' : ($legacyDsn ? 'MOKU_DSN' : '');
        $testDriver = strtolower(trim((string) getenv('OPENCONCEPT_TEST_DRIVER')));
        $usesExplicitTestDatabase = (PHP_SAPI === 'cli'
                || (PHP_SAPI === 'cli-server'
                    && hash_equals('1', trim((string) getenv('OPENCONCEPT_ISOLATED_HTTP_TEST')))))
            && in_array($testDriver, ['sqlite', 'mysql'], true)
            && $dsn !== '';
        // Standalone tests explicitly select an isolated DSN. Ignoring this
        // marker would make them follow the application's managed canonical
        // state and could mutate a live local database.
        $canonicalBackend = $usesExplicitTestDatabase
            ? 'sqlite'
            : (string) ($runtimeState['canonical_backend'] ?? 'sqlite');
        $adapter = null;
        $config = [];
        $credentials = [];

        if ($canonicalBackend !== 'sqlite') {
            $adapter = $this->adapterRegistry->get($canonicalBackend);
            $adapterState = $runtimeState['adapters'][$canonicalBackend] ?? null;
            if (!is_array($adapterState) || !is_array($adapterState['config'] ?? null)) {
                throw new DatabaseUnavailableException(
                    $canonicalBackend,
                    'The canonical database adapter state is incomplete.',
                    null,
                    'adapter_state_invalid'
                );
            }
            if (($adapterState['workspace_deletion']['status'] ?? null) === 'completed') {
                throw new DatabaseUnavailableException(
                    $canonicalBackend,
                    'This OpenConcept workspace has been deleted from its canonical database.',
                    null,
                    'workspace_deleted'
                );
            }
            $config = $adapterState['config'];
            $credentials = $this->adapterStateStore->credentials($canonicalBackend);
            $this->backendId = $canonicalBackend;
        } elseif ($dsn !== '') {
            $adapter = $this->adapterRegistry->forDsn($dsn);
            $configuredPrefix = getenv('OPENCONCEPT_TABLE_PREFIX');
            $config = [
                'dsn' => $dsn,
                'prefix' => $configuredPrefix === false
                    ? $adapter->defaultTablePrefix()
                    : trim((string) $configuredPrefix),
            ];
            $credentials = [
                'username' => (string) (getenv('OPENCONCEPT_DB_USER') ?: getenv('MOKU_DB_USER') ?: ''),
                'password' => (string) (getenv('OPENCONCEPT_DB_PASSWORD') ?: getenv('MOKU_DB_PASSWORD') ?: ''),
            ];
            $this->backendId = $adapter->id();
            $this->manualExternalBackend = $this->backendId !== 'sqlite';
        } else {
            $databasePath = $storagePath . DIRECTORY_SEPARATOR . 'openconcept.sqlite';
            $legacyPath = $storagePath . DIRECTORY_SEPARATOR . 'moku.sqlite';
            if (!is_file($databasePath) && is_file($legacyPath) && !rename($legacyPath, $databasePath)) {
                $databasePath = $legacyPath;
            }
            $adapter = $this->adapterRegistry->get('sqlite');
            $config = ['path' => $databasePath, 'prefix' => ''];
        }

        $this->connectionInfo = $this->buildConnectionInfo(
            $canonicalBackend !== 'sqlite'
                ? 'adapter_managed'
                : ($this->manualExternalBackend ? 'environment' : 'native_sqlite'),
            $config,
            $dsnVariable
        );

        // Seal the workspace to one credential-free physical DB identity
        // before opening a connection or allowing schema/data writes. The
        // binding persists across requests so sequentially conflicting web,
        // worker, cron, or manually modified configurations also fail closed.
        // Explicit CLI test databases are intentionally isolated from the
        // live workspace and therefore use a process-local binding. This
        // preserves conflict coverage inside a test without writing test
        // metadata into the application's real storage directory.
        $this->selectionGuard = new DatabaseSelectionGuard(
            $usesExplicitTestDatabase
                ? $this->storagePath . '|explicit-test-dsn|' . hash('sha256', $dsn)
                : $this->storagePath,
            !$usesExplicitTestDatabase
        );
        try {
            $this->selectionLease = $this->selectionGuard->acquire(
                $this->backendId,
                $this->databaseSelectionIdentity($config)
            );
        } catch (DatabaseAdapterException $exception) {
            throw new DatabaseUnavailableException(
                $this->backendId,
                'OpenConcept stopped because it could not prove one canonical database selection.',
                $exception,
                $exception->failureCode()
            );
        }

        // The published catalog and its immutable release seal are local
        // prerequisites. Reject a missing, modified, or incomplete standard
        // before opening any canonical database connection.
        try {
            $catalog = CoreSchemaCatalog::load();
        } catch (CoreSchemaException $exception) {
            throw new DatabaseUnavailableException(
                $this->backendId,
                'The canonical OpenConcept schema standard is unavailable or inconsistent.',
                $exception,
                $exception->failureCode()
            );
        }

        try {
            $this->pdo = $adapter->connect($config, $credentials);
        } catch (Throwable $exception) {
            if ($this->backendId !== 'sqlite') {
                $failureCode = $exception instanceof DatabaseAdapterException
                    ? $exception->failureCode()
                    : 'connection_failed';
                $details = $exception instanceof DatabaseAdapterException ? $exception->details() : [];
                if ($failureCode === 'permissions_insufficient') {
                    try {
                        $details = $this->privilegeManager->prepareConnectionPermissionFailure(
                            $this->backendId,
                            $config,
                            (string) ($credentials['username'] ?? '')
                        );
                    } catch (Throwable) {
                        // Keep the original server classification even when a
                        // local recovery artifact cannot be prepared.
                    }
                }
                try {
                    if ($canonicalBackend !== 'sqlite') {
                        $adapterState = $this->adapterStateStore->load()['adapters'][$canonicalBackend] ?? [];
                        if (!is_array($adapterState)
                            || ($adapterState['lifecycle'] ?? '') !== 'ERROR'
                            || ($adapterState['last_error_code'] ?? '') !== $failureCode) {
                            $this->adapterStateStore->recordFailure(
                                $canonicalBackend,
                                'runtime_connection',
                                $failureCode,
                                'The canonical database connection could not be established.',
                                true,
                                $details
                            );
                        }
                    }
                } catch (Throwable) {
                    // Preserve the original connection error. State persistence
                    // failure must never trigger selection of an older DB.
                }
                throw new DatabaseUnavailableException(
                    $this->backendId,
                    'The canonical OpenConcept database connection failed.',
                    $exception,
                    $failureCode,
                    $details
                );
            }
            throw $exception;
        }
        if (!$this->pdo instanceof PrefixedPDO) {
            throw new DatabaseAdapterException(
                'The canonical adapter did not provide a lease-capable PDO.',
                'maintenance_barrier_unavailable'
            );
        }
        $this->pdo->attachDatabaseMaintenanceLease($this->maintenanceMode, $this->maintenanceLease);
        $this->maintenanceLease = null;
        $this->pdo->attachDatabaseSelectionLease($this->selectionGuard, $this->selectionLease);
        $this->selectionLease = null;

        if ($this->backendId !== 'sqlite') {
            try {
                $this->privilegeManager->verify($this->pdo, $this->backendId, $config);
            } catch (DatabaseAdapterException $exception) {
                if ($canonicalBackend !== 'sqlite') {
                    try {
                        $this->adapterStateStore->recordFailure(
                            $this->backendId,
                            'runtime_permissions',
                            $exception->failureCode(),
                            'The canonical database principal does not satisfy the saved permission definition.',
                            true,
                            $exception->details()
                        );
                    } catch (Throwable) {
                        // The permission failure and locally generated recovery
                        // artifact remain authoritative if state persistence fails.
                    }
                }
                throw new DatabaseUnavailableException(
                    $this->backendId,
                    'The canonical OpenConcept database permission check failed.',
                    $exception,
                    $exception->failureCode(),
                    $exception->details()
                );
            } catch (Throwable $exception) {
                throw new DatabaseUnavailableException(
                    $this->backendId,
                    'The database permission definition could not be persisted or verified.',
                    $exception,
                    'permission_definition_unavailable'
                );
            }
        }

        try {
            $schemaRuntime = new CoreSchemaRuntime($this->pdo, $catalog);
            $writesAllowed = $this->canonicalSchemaWritesAllowed();

            // A current database is verified before any legacy CREATE/ALTER
            // path runs. Drift must stop startup; old migrations may not hide
            // or auto-repair a broken canonical schema.
            $currentSchema = $schemaRuntime->preflight($writesAllowed);
            $requiresBaseProvisioning = $schemaRuntime->requiresBaseProvisioning();
            if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                && $currentSchema === null
                && $requiresBaseProvisioning) {
                $schemaRuntime->provisionFreshSqlite(function (): void {
                    $this->migrateSqlite();
                    $this->ensureSqliteColumns();
                });
            }
            // preflight() already performs the complete live-schema proof for
            // a current installation. Only enter boot when provisioning or a
            // recognized version upgrade is actually required; otherwise the
            // same expensive MySQL catalog inspection would run twice for
            // every HTTP request.
            if (!is_array($currentSchema)) {
                $schemaRuntime->boot($writesAllowed);
            }
            if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                && $writesAllowed) {
                // Preserve the existing non-schema branding data migration,
                // but only after the canonical shape has been proven.
                $this->migrateBranding();
            }
        } catch (Throwable $exception) {
            $schemaException = $exception instanceof CoreSchemaException
                ? $exception
                : new CoreSchemaException([
                    'reason' => 'canonical_schema_lifecycle_failed',
                ], $exception);
            if ($canonicalBackend !== 'sqlite') {
                try {
                    $this->adapterStateStore->recordFailure(
                        $this->backendId,
                        'canonical_schema',
                        $schemaException->failureCode(),
                        'The canonical database schema does not match its versioned standard.',
                        true
                    );
                } catch (Throwable) {
                    // Preserve the canonical schema failure even if local
                    // lifecycle metadata cannot be updated.
                }
            }
            throw new DatabaseUnavailableException(
                $this->backendId,
                'The canonical OpenConcept database schema is inconsistent.',
                $schemaException,
                $schemaException->failureCode()
            );
        }

        if ($canonicalBackend === 'mysql' && !$this->manualExternalBackend) {
            $this->ensureManagedMySqlWorkspaceOwnership($config, $runtimeState);
        }

        } catch (Throwable $exception) {
            if (isset($this->pdo) && $this->pdo instanceof PrefixedPDO) {
                $this->pdo->releaseDatabaseSelectionLease();
                $this->pdo->releaseDatabaseMaintenanceLease();
            }
            if (is_resource($this->selectionLease)) {
                $this->selectionGuard->release($this->selectionLease);
                $this->selectionLease = null;
            }
            if (is_resource($this->maintenanceLease)) {
                $this->maintenanceMode->releaseShared($this->maintenanceLease);
                $this->maintenanceLease = null;
            }
            throw $exception;
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<string, scalar|null> $context */
    public function beginAdapterCutover(array $context = []): void
    {
        $deploymentGate = trim((string) getenv('OPENCONCEPT_DEPLOYMENT_WRITE_GATE_PATH'));
        if ($deploymentGate === '') {
            $deploymentGate = $this->storagePath . DIRECTORY_SEPARATOR . '.deployment-write-gate';
        }
        if (is_file($deploymentGate)) {
            throw new DatabaseAdapterException(
                'Application deployment maintenance is active.',
                'deployment_maintenance'
            );
        }
        if (!$this->pdo instanceof PrefixedPDO) {
            throw new DatabaseAdapterException(
                'The database writer-drain lease is unavailable.',
                'maintenance_barrier_unavailable'
            );
        }
        try {
            $this->pdo->beginAdapterCutover($context);
            // A deployment can acquire its shared writer lease after the
            // first marker check but publish the marker while this upgrade is
            // draining that lease. Re-check under EX before any cutover work;
            // otherwise deployment and adapter activation could overlap.
            clearstatcache(true, $deploymentGate);
            if (is_file($deploymentGate)) {
                $this->pdo->finishAdapterCutover(false);
                throw new DatabaseAdapterException(
                    'Application deployment maintenance is active.',
                    'deployment_maintenance'
                );
            }
        } catch (DatabaseAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException(
                'The database writer-drain barrier could not be established.',
                'maintenance_barrier_unavailable',
                $exception
            );
        }
    }

    /** @param array<string, scalar|null> $details */
    public function updateAdapterCutoverStage(
        string $stage,
        array $details = [],
        bool $cancellable = true
    ): void {
        $this->pdo->updateAdapterCutoverStage($stage, $details, $cancellable);
    }

    public function throwIfAdapterCutoverCancelled(): void
    {
        $this->pdo->throwIfAdapterCutoverCancelled();
    }

    public function finishAdapterCutover(bool $activated): void
    {
        try {
            $this->pdo->finishAdapterCutover($activated);
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException(
                'The database writer-drain barrier could not be released safely.',
                'maintenance_barrier_release_failed',
                $exception
            );
        }
    }

    public function backendId(): string
    {
        return $this->backendId;
    }

    /**
     * Exposes feature discovery for the current authenticated connection
     * without exposing the adapter's stored credentials to plugins.
     */
    public function capabilities(): DatabaseCapabilitiesInterface
    {
        $adapter = $this->adapterRegistry->get($this->backendId);
        if ($adapter instanceof DatabaseCapabilityProviderInterface) {
            return $adapter->capabilities($this->pdo);
        }
        return new DatabaseCapabilities(
            $adapter->driver(),
            in_array($adapter->driver(), ['sqlite', 'mysql', 'pgsql'], true)
        );
    }

    public function isManualExternalBackend(): bool
    {
        return $this->manualExternalBackend;
    }

    /**
     * Non-secret connection diagnostics for the system administrator UI.
     * Credentials and the complete DSN are never retained in this payload.
     *
     * @return array<string, mixed>
     */
    public function connectionInfo(): array
    {
        return $this->connectionInfo;
    }

    public function adapterRegistry(): DatabaseAdapterRegistry
    {
        return $this->adapterRegistry;
    }

    public function adapterStateStore(): DatabaseAdapterStateStore
    {
        return $this->adapterStateStore;
    }

    public function privilegeManager(): DatabasePrivilegeManager
    {
        return $this->privilegeManager;
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    /**
     * Re-run the immutable Core proof after plugin boot. Plugins may add only
     * their plugin_* relations; changing Core or adding an unowned relation
     * invalidates the whole process immediately.
     *
     * @return array<string, mixed>
     */
    public function verifyCanonicalSchema(): array
    {
        try {
            return (new CoreSchemaVerifier(CoreSchemaCatalog::load()))->verify($this->pdo);
        } catch (CoreSchemaException $exception) {
            throw new DatabaseUnavailableException(
                $this->backendId,
                'The canonical OpenConcept database schema is inconsistent.',
                $exception,
                $exception->failureCode()
            );
        }
    }

    private function canonicalSchemaWritesAllowed(): bool
    {
        if (defined('OPENCONCEPT_PLUGIN_VERIFY_ONLY')
            && constant('OPENCONCEPT_PLUGIN_VERIFY_ONLY') === true) {
            return false;
        }
        $gatePath = trim((string) getenv('OPENCONCEPT_DEPLOYMENT_WRITE_GATE_PATH'));
        if ($gatePath === '') {
            $gatePath = $this->storagePath . DIRECTORY_SEPARATOR . '.deployment-write-gate';
        }
        return !is_file($gatePath);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function buildConnectionInfo(string $method, array $config, string $dsnVariable): array
    {
        $dsnParts = $this->dsnParts($config);

        $source = match ($method) {
            'adapter_managed' => 'adapter_state',
            'environment' => class_exists(Environment::class) && $dsnVariable !== ''
                ? Environment::source($dsnVariable)
                : 'process_environment',
            default => 'native_sqlite',
        };
        if ($method === 'environment' && $source === '') {
            $source = 'process_environment';
        }

        $portValue = $config['port'] ?? ($dsnParts['port'] ?? null);
        $port = filter_var($portValue, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        $sslMode = strtolower($this->safeConnectionValue($config['sslmode'] ?? ($dsnParts['sslmode'] ?? ''), 24));
        if (!in_array($sslMode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            $sslMode = '';
        }

        return [
            'state' => 'connected',
            'backend' => $this->backendId,
            'method' => $method,
            'source' => $source,
            'environment_variable' => $method === 'environment' ? $dsnVariable : '',
            'host' => $this->safeConnectionValue($config['host'] ?? ($dsnParts['host'] ?? ''), 255),
            'port' => $port === false ? null : (int) $port,
            'database' => $this->safeConnectionValue(
                $config['database'] ?? ($dsnParts['dbname'] ?? ($dsnParts['database'] ?? '')),
                128
            ),
            'table_prefix' => $this->safeConnectionValue($config['prefix'] ?? '', 128),
            'sslmode' => $sslMode,
        ];
    }

    /**
     * Build a stable physical selection identity without retaining usernames,
     * passwords, or the complete DSN in the guard record.
     *
     * @param array<string, mixed> $config
     * @return array<string, scalar|null>
     */
    private function databaseSelectionIdentity(array $config): array
    {
        $dsnParts = $this->dsnParts($config);
        if ($this->backendId === 'sqlite') {
            $path = trim((string) ($config['path'] ?? ''));
            $dsn = trim((string) ($config['dsn'] ?? ''));
            if ($path === '' && str_starts_with(strtolower($dsn), 'sqlite:')) {
                $path = substr($dsn, strlen('sqlite:'));
            }
            if ($path === '') {
                return ['connection_hash' => hash('sha256', $dsn)];
            }

            $resolvedPath = realpath($path);
            if ($resolvedPath === false) {
                $parent = realpath(dirname($path));
                $resolvedPath = $parent !== false
                    ? $parent . DIRECTORY_SEPARATOR . basename($path)
                    : (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1
                        ? $path
                        : getcwd() . DIRECTORY_SEPARATOR . $path);
            }
            $normalizedPath = str_replace('\\', '/', $resolvedPath);
            $resolvedStorage = realpath($this->storagePath);
            $normalizedStorage = rtrim(str_replace(
                '\\',
                '/',
                $resolvedStorage !== false ? $resolvedStorage : $this->storagePath
            ), '/');
            if (DIRECTORY_SEPARATOR === '\\') {
                $normalizedPath = strtolower($normalizedPath);
                $normalizedStorage = strtolower($normalizedStorage);
            }
            $storagePrefix = $normalizedStorage . '/';
            if (str_starts_with($normalizedPath, $storagePrefix)) {
                $normalizedPath = 'storage:/' . substr($normalizedPath, strlen($storagePrefix));
            }
            return ['location' => $normalizedPath];
        }

        $host = strtolower($this->safeConnectionValue(
            $config['host'] ?? ($dsnParts['host'] ?? ''),
            255
        ));
        $socket = $this->safeConnectionValue(
            $config['unix_socket'] ?? ($dsnParts['unix_socket'] ?? ''),
            512
        );
        $database = $this->safeConnectionValue(
            $config['database'] ?? ($dsnParts['dbname'] ?? ($dsnParts['database'] ?? '')),
            128
        );
        $portValue = $config['port'] ?? ($dsnParts['port'] ?? null);
        $port = filter_var($portValue, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false || $port === null) {
            $port = $this->backendId === 'mysql' ? 3306 : ($this->backendId === 'postgresql' ? 5432 : null);
        }

        $identity = [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'table_prefix' => $this->safeConnectionValue($config['prefix'] ?? '', 128),
            'unix_socket' => $socket,
        ];
        if ($host === '' && $socket === '' && $database === '') {
            $identity['connection_hash'] = hash('sha256', trim((string) ($config['dsn'] ?? '')));
        }
        return $identity;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function dsnParts(array $config): array
    {
        $dsnParts = [];
        $dsn = trim((string) ($config['dsn'] ?? ''));
        if ($dsn === '' || !str_contains($dsn, ':')) {
            return $dsnParts;
        }
        [, $options] = explode(':', $dsn, 2);
        foreach (explode(';', $options) as $option) {
            if (!str_contains($option, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $option, 2));
            $key = strtolower($key);
            if (in_array($key, ['host', 'port', 'dbname', 'database', 'sslmode', 'unix_socket'], true)
                && !array_key_exists($key, $dsnParts)) {
                $dsnParts[$key] = $value;
            }
        }
        return $dsnParts;
    }

    private function safeConnectionValue(mixed $value, int $maximumLength): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim((string) $value);
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return '';
        }
        return substr($value, 0, $maximumLength);
    }

    /**
     * Adopt older ACTIVE installations once. New migrations already create
     * this ownership record before any application table is provisioned.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $runtimeState
     */
    private function ensureManagedMySqlWorkspaceOwnership(array $config, array $runtimeState): void
    {
        $storedOwnership = $runtimeState['adapters']['mysql']['workspace_ownership'] ?? null;
        $ownership = null;
        try {
            $identity = $this->adapterStateStore->workspaceIdentity((string) ($config['prefix'] ?? ''));
            if (is_array($storedOwnership) && ($storedOwnership['status'] ?? null) === 'active') {
                if (!hash_equals(
                    (string) ($storedOwnership['workspace_id'] ?? ''),
                    (string) $identity['workspace_id']
                ) || !hash_equals(
                    (string) ($storedOwnership['table_prefix'] ?? ''),
                    (string) $identity['table_prefix']
                )) {
                    throw new DatabaseAdapterException(
                        'The local workspace identity does not match the canonical MySQL ownership state.',
                        'workspace_identity_conflict'
                    );
                }
                return;
            }
            $schema = (new DatabaseSchemaInspector())->inspect($this->pdo);
            $ownership = new DatabaseWorkspaceOwnership($this->pdo, $identity);
            $ownership->acquire();
            $ownership->reserve($schema, true);
            $report = $ownership->finalize($schema);
            $this->adapterStateStore->setLifecycle('mysql', 'ACTIVE', [
                'workspace_ownership' => $report,
                'last_error_code' => null,
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $failureCode = $exception instanceof DatabaseAdapterException
                ? $exception->failureCode()
                : 'workspace_ownership_failed';
            try {
                $this->adapterStateStore->recordFailure(
                    'mysql',
                    'workspace_ownership',
                    $failureCode,
                    'The canonical MySQL workspace ownership check failed.',
                    true
                );
            } catch (Throwable) {
            }
            throw new DatabaseUnavailableException(
                'mysql',
                'The canonical MySQL workspace ownership could not be verified.',
                $exception,
                $failureCode
            );
        } finally {
            if ($ownership !== null) {
                $ownership->release();
            }
        }
    }

    private function migrateSqlite(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    avatar_color TEXT NOT NULL DEFAULT '#5E6AD2',
    avatar_kind TEXT NOT NULL DEFAULT 'initials',
    avatar_value TEXT NOT NULL DEFAULT '',
    ui_locale TEXT NOT NULL DEFAULT 'ja-JP',
    role TEXT NOT NULL DEFAULT 'editor',
    department TEXT NOT NULL DEFAULT 'プロダクト',
    active INTEGER NOT NULL DEFAULT 1,
    must_change_password INTEGER NOT NULL DEFAULT 0,
    invited_at TEXT NULL,
    last_login_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NULL REFERENCES pages(id) ON DELETE SET NULL,
    sort_order INTEGER NOT NULL DEFAULT 0,
    title TEXT NOT NULL DEFAULT '無題',
    icon TEXT NOT NULL DEFAULT '📄',
    cover TEXT NOT NULL DEFAULT 'mint',
    status TEXT NOT NULL DEFAULT 'draft',
    category TEXT NOT NULL DEFAULT 'ナレッジ',
    tags_json TEXT NOT NULL DEFAULT '[]',
    manual_tags_json TEXT NULL DEFAULT NULL,
    blocks_json TEXT NOT NULL DEFAULT '[]',
    plain_text TEXT NOT NULL DEFAULT '',
    visibility TEXT NOT NULL DEFAULT 'company',
    access_department TEXT NOT NULL DEFAULT '',
    comments_enabled INTEGER NOT NULL DEFAULT 1,
    language_code TEXT NOT NULL DEFAULT 'und',
    translation_group_id TEXT NULL,
    source_page_id INTEGER NULL REFERENCES pages(id) ON DELETE SET NULL,
    source_revision INTEGER NULL,
    translation_status TEXT NOT NULL DEFAULT 'original',
    translation_block_map_json TEXT NULL,
    content_revision INTEGER NOT NULL DEFAULT 1,
    author_id INTEGER NOT NULL REFERENCES users(id),
    updated_by INTEGER NOT NULL REFERENCES users(id),
    is_favorite INTEGER NOT NULL DEFAULT 0,
    archived_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS page_access_members (
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    granted_by INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (page_id, user_id)
);

CREATE TABLE IF NOT EXISTS page_access_departments (
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    department TEXT COLLATE BINARY NOT NULL,
    granted_by INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (page_id, department)
);

CREATE TABLE IF NOT EXISTS page_public_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    root_page_id INTEGER NOT NULL UNIQUE REFERENCES pages(id) ON DELETE CASCADE,
    token TEXT NOT NULL UNIQUE CHECK (length(token) = 43),
    public_slug TEXT NULL CHECK (public_slug IS NULL OR (length(public_slug) BETWEEN 1 AND 80)),
    enabled INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)),
    publication_locale TEXT NULL CHECK (publication_locale IS NULL OR (length(publication_locale) BETWEEN 1 AND 35)),
    revision INTEGER NOT NULL DEFAULT 1 CHECK (revision > 0),
    published_revision INTEGER NULL CHECK (published_revision IS NULL OR published_revision > 0),
    manifest_json TEXT NULL,
    source_hash TEXT NULL CHECK (source_hash IS NULL OR length(source_hash) = 64),
    published_at TEXT NULL,
    operation_state TEXT NOT NULL DEFAULT 'idle' CHECK (operation_state IN ('idle', 'publishing', 'stopping', 'failed')),
    operation_id TEXT NULL CHECK (operation_id IS NULL OR (length(operation_id) BETWEEN 1 AND 80)),
    pending_manifest_json TEXT NULL,
    operation_started_at TEXT NULL,
    created_by INTEGER NOT NULL REFERENCES users(id),
    updated_by INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS page_public_share_pages (
    share_id INTEGER NOT NULL REFERENCES page_public_shares(id) ON DELETE CASCADE,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    added_by INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (share_id, page_id)
);

CREATE TABLE IF NOT EXISTS revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    icon TEXT NOT NULL,
    blocks_json TEXT NOT NULL,
    meta_json TEXT NOT NULL DEFAULT '{}',
    created_by INTEGER NOT NULL REFERENCES users(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    parent_id INTEGER NULL REFERENCES comments(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id),
    body TEXT NOT NULL,
    resolved_at TEXT NULL,
    edited_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    message TEXT NOT NULL,
    page_id INTEGER NULL REFERENCES pages(id) ON DELETE CASCADE,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id),
    action TEXT NOT NULL,
    subject_type TEXT NOT NULL,
    subject_id INTEGER NOT NULL,
    detail_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL UNIQUE,
    mime_type TEXT NOT NULL,
    category TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    uploaded_by INTEGER NOT NULL REFERENCES users(id),
    page_id INTEGER NULL REFERENCES pages(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ai_conversations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title TEXT NOT NULL DEFAULT '新しいチャット',
    turn_count INTEGER NOT NULL DEFAULT 0,
    last_page_id INTEGER NULL REFERENCES pages(id) ON DELETE SET NULL,
    last_page_title TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at TEXT NULL
);

CREATE TABLE IF NOT EXISTS ai_chat_turns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL REFERENCES ai_conversations(id) ON DELETE CASCADE,
    turn_index INTEGER NOT NULL,
    mode TEXT NOT NULL DEFAULT 'search',
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    page_id INTEGER NULL REFERENCES pages(id) ON DELETE SET NULL,
    page_title TEXT NOT NULL DEFAULT '',
    sufficient INTEGER NOT NULL DEFAULT 0,
    sources_json TEXT NOT NULL DEFAULT '[]',
    candidate_count INTEGER NOT NULL DEFAULT 0,
    model TEXT NOT NULL DEFAULT '',
    reasoning_effort TEXT NOT NULL DEFAULT 'low',
    response_id TEXT NOT NULL DEFAULT '',
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    degraded INTEGER NOT NULL DEFAULT 0,
    review_status TEXT NOT NULL DEFAULT 'pending',
    review_note TEXT NULL,
    reviewed_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
    reviewed_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (conversation_id, turn_index)
);

CREATE TABLE IF NOT EXISTS rag_source_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    source_hash TEXT NOT NULL,
    source_schema_version INTEGER NOT NULL DEFAULT 4,
    title TEXT NOT NULL,
    category TEXT NOT NULL,
    tags_json TEXT NOT NULL,
    blocks_json TEXT NOT NULL,
    plain_text TEXT NOT NULL,
    visibility TEXT NOT NULL,
    access_department TEXT NOT NULL DEFAULT '',
    author_id INTEGER NOT NULL REFERENCES users(id),
    updated_by INTEGER NOT NULL REFERENCES users(id),
    source_created_at TEXT NOT NULL,
    source_updated_at TEXT NOT NULL,
    published_at TEXT NULL,
    language_code TEXT NOT NULL DEFAULT 'und',
    translation_group_id TEXT NULL,
    source_page_id INTEGER NULL,
    translation_role TEXT NOT NULL DEFAULT 'original',
    translation_status TEXT NOT NULL DEFAULT 'original',
    source_revision INTEGER NULL,
    content_revision INTEGER NOT NULL DEFAULT 1,
    is_current INTEGER NOT NULL DEFAULT 1,
    captured_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (page_id, source_hash)
);

CREATE TABLE IF NOT EXISTS rag_source_units (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_document_id INTEGER NOT NULL REFERENCES rag_source_documents(id) ON DELETE CASCADE,
    unit_index INTEGER NOT NULL,
    unit_key TEXT NOT NULL,
    block_type TEXT NOT NULL,
    heading_path_json TEXT NOT NULL,
    content TEXT NOT NULL,
    content_sha256 TEXT NOT NULL,
    char_count INTEGER NOT NULL,
    UNIQUE (source_document_id, unit_index),
    UNIQUE (source_document_id, unit_key)
);

CREATE TABLE IF NOT EXISTS rag_source_access_members (
    source_document_id INTEGER NOT NULL REFERENCES rag_source_documents(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    PRIMARY KEY (source_document_id, user_id)
);

CREATE TABLE IF NOT EXISTS rag_source_access_departments (
    source_document_id INTEGER NOT NULL REFERENCES rag_source_documents(id) ON DELETE CASCADE,
    department TEXT COLLATE BINARY NOT NULL,
    PRIMARY KEY (source_document_id, department)
);

CREATE TABLE IF NOT EXISTS rag_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    source_hash TEXT NOT NULL,
    prompt_version TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    reasoning_effort TEXT NOT NULL DEFAULT 'medium',
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    available_at TEXT NOT NULL,
    locked_at TEXT NULL,
    locked_by TEXT NULL,
    last_error TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TEXT NULL,
    UNIQUE (page_id, source_hash, prompt_version)
);

CREATE TABLE IF NOT EXISTS rag_page_profiles (
    page_id INTEGER PRIMARY KEY REFERENCES pages(id) ON DELETE CASCADE,
    source_hash TEXT NOT NULL,
    language TEXT NOT NULL,
    summary TEXT NOT NULL,
    short_summary TEXT NOT NULL,
    tags_json TEXT NOT NULL,
    keywords_json TEXT NOT NULL,
    entities_json TEXT NOT NULL,
    questions_json TEXT NOT NULL,
    model TEXT NOT NULL,
    reasoning_effort TEXT NOT NULL,
    prompt_version TEXT NOT NULL,
    response_id TEXT NOT NULL DEFAULT '',
    input_chars INTEGER NOT NULL DEFAULT 0,
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    latency_ms INTEGER NOT NULL DEFAULT 0,
    analyzed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rag_chunks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    source_hash TEXT NOT NULL,
    prompt_version TEXT NOT NULL,
    chunk_index INTEGER NOT NULL,
    chunk_key TEXT NOT NULL,
    title TEXT NOT NULL,
    heading_path_json TEXT NOT NULL,
    unit_ids_json TEXT NOT NULL,
    content TEXT NOT NULL,
    chunk_summary TEXT NOT NULL,
    keywords_json TEXT NOT NULL,
    search_text TEXT NOT NULL DEFAULT '',
    token_estimate INTEGER NOT NULL DEFAULT 0,
    char_count INTEGER NOT NULL DEFAULT 0,
    model TEXT NOT NULL,
    reasoning_effort TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (page_id, source_hash, prompt_version, chunk_index)
);

CREATE TABLE IF NOT EXISTS rag_generated_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    source_hash TEXT NOT NULL,
    prompt_version TEXT NOT NULL,
    tag TEXT NOT NULL,
    normalized_tag TEXT NOT NULL,
    relevance REAL NOT NULL DEFAULT 0,
    model TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (page_id, source_hash, prompt_version, normalized_tag)
);

CREATE TABLE IF NOT EXISTS rag_canonical_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    canonical_name TEXT NOT NULL,
    normalized_name TEXT NOT NULL UNIQUE,
    tag_type TEXT NOT NULL DEFAULT 'general',
    created_source TEXT NOT NULL DEFAULT 'ai',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rag_tag_aliases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tag_id INTEGER NOT NULL REFERENCES rag_canonical_tags(id) ON DELETE CASCADE,
    alias TEXT NOT NULL,
    normalized_alias TEXT NOT NULL UNIQUE,
    created_source TEXT NOT NULL DEFAULT 'ai',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (tag_id, normalized_alias)
);

CREATE TABLE IF NOT EXISTS rag_page_tag_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    source_hash TEXT NOT NULL DEFAULT '',
    prompt_version TEXT NOT NULL DEFAULT '',
    tag_id INTEGER NOT NULL REFERENCES rag_canonical_tags(id) ON DELETE CASCADE,
    metadata_field TEXT NOT NULL,
    source TEXT NOT NULL,
    confidence REAL NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (page_id, source_hash, prompt_version, tag_id, metadata_field, source)
);

CREATE TABLE IF NOT EXISTS rag_source_unit_blocks (
    source_unit_id INTEGER PRIMARY KEY REFERENCES rag_source_units(id) ON DELETE CASCADE,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    block_id TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rag_block_metadata (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    source_hash TEXT NOT NULL,
    prompt_version TEXT NOT NULL,
    block_id TEXT NOT NULL,
    block_type TEXT NOT NULL,
    summary TEXT NOT NULL,
    keywords_json TEXT NOT NULL,
    entities_json TEXT NOT NULL,
    model TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (page_id, source_hash, prompt_version, block_id)
);

CREATE TABLE IF NOT EXISTS rag_chunk_blocks (
    chunk_id INTEGER NOT NULL REFERENCES rag_chunks(id) ON DELETE CASCADE,
    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
    block_id TEXT NOT NULL,
    source_hash TEXT NOT NULL,
    PRIMARY KEY (chunk_id, block_id)
);

CREATE INDEX IF NOT EXISTS idx_pages_parent ON pages(parent_id);
CREATE INDEX IF NOT EXISTS idx_pages_updated ON pages(updated_at);
CREATE INDEX IF NOT EXISTS idx_pages_language ON pages(language_code);
CREATE INDEX IF NOT EXISTS idx_pages_translation_source ON pages(source_page_id, language_code);
CREATE INDEX IF NOT EXISTS idx_pages_translation_group ON pages(translation_group_id);
CREATE INDEX IF NOT EXISTS idx_page_access_user ON page_access_members(user_id, page_id);
CREATE INDEX IF NOT EXISTS idx_page_access_department ON page_access_departments(department, page_id);
CREATE INDEX IF NOT EXISTS idx_page_public_share_pages_page ON page_public_share_pages(page_id, share_id);
CREATE INDEX IF NOT EXISTS idx_comments_page ON comments(page_id);
CREATE INDEX IF NOT EXISTS idx_revisions_page ON revisions(page_id);
CREATE INDEX IF NOT EXISTS idx_files_category ON files(category, created_at);
CREATE INDEX IF NOT EXISTS idx_files_page ON files(page_id);
CREATE INDEX IF NOT EXISTS idx_ai_conversations_user ON ai_conversations(user_id, updated_at);
CREATE INDEX IF NOT EXISTS idx_ai_chat_turns_conversation ON ai_chat_turns(conversation_id, turn_index);
CREATE INDEX IF NOT EXISTS idx_ai_chat_turns_review ON ai_chat_turns(review_status, created_at);
CREATE INDEX IF NOT EXISTS idx_rag_source_current ON rag_source_documents(page_id, is_current);
CREATE INDEX IF NOT EXISTS idx_rag_source_units_document ON rag_source_units(source_document_id, unit_index);
CREATE INDEX IF NOT EXISTS idx_rag_source_access_user ON rag_source_access_members(user_id, source_document_id);
CREATE INDEX IF NOT EXISTS idx_rag_source_access_department ON rag_source_access_departments(department, source_document_id);
CREATE INDEX IF NOT EXISTS idx_rag_jobs_ready ON rag_jobs(status, available_at, id);
CREATE INDEX IF NOT EXISTS idx_rag_chunks_active ON rag_chunks(page_id, is_active, chunk_index);
CREATE INDEX IF NOT EXISTS idx_rag_chunks_hash ON rag_chunks(source_hash);
CREATE INDEX IF NOT EXISTS idx_rag_generated_tags_active ON rag_generated_tags(page_id, is_active);
CREATE INDEX IF NOT EXISTS idx_rag_tag_aliases_tag ON rag_tag_aliases(tag_id);
CREATE INDEX IF NOT EXISTS idx_rag_page_tag_links_page ON rag_page_tag_links(page_id, source, is_active, metadata_field);
CREATE INDEX IF NOT EXISTS idx_rag_page_tag_links_filter ON rag_page_tag_links(metadata_field, tag_id, is_active, page_id);
CREATE INDEX IF NOT EXISTS idx_rag_source_unit_blocks_page ON rag_source_unit_blocks(page_id, block_id);
CREATE INDEX IF NOT EXISTS idx_rag_block_metadata_active ON rag_block_metadata(page_id, is_active, block_id);
CREATE INDEX IF NOT EXISTS idx_rag_chunk_blocks_page ON rag_chunk_blocks(page_id, block_id, chunk_id);
SQL);
    }

    private function ensureSqliteColumns(): void
    {
        $columns = $this->pdo->query('PRAGMA table_info(users)')->fetchAll();
        $names = array_column($columns, 'name');
        $required = [
            'must_change_password' => 'INTEGER NOT NULL DEFAULT 0',
            'invited_at' => 'TEXT NULL',
            'last_login_at' => 'TEXT NULL',
            'avatar_kind' => "TEXT NOT NULL DEFAULT 'initials'",
            'avatar_value' => "TEXT NOT NULL DEFAULT ''",
            'ui_locale' => "TEXT NOT NULL DEFAULT 'ja-JP'",
        ];
        foreach ($required as $name => $definition) {
            if (!in_array($name, $names, true)) {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN {$name} {$definition}");
            }
        }

        $pageColumns = $this->pdo->query('PRAGMA table_info(pages)')->fetchAll();
        $pageNames = array_column($pageColumns, 'name');
        if (!in_array('sort_order', $pageNames, true)) {
            $this->pdo->exec('ALTER TABLE pages ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('access_department', $pageNames, true)) {
            $this->pdo->exec("ALTER TABLE pages ADD COLUMN access_department TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('manual_tags_json', $pageNames, true)) {
            $this->pdo->exec('ALTER TABLE pages ADD COLUMN manual_tags_json TEXT NULL DEFAULT NULL');
            // Existing visible tags are conservatively treated as manual so a
            // migration can never remove a tag entered by a person.
            $this->pdo->exec('UPDATE pages SET manual_tags_json = tags_json');
        }
        $translationColumns = [
            'language_code' => "TEXT NOT NULL DEFAULT 'und'",
            'translation_group_id' => 'TEXT NULL',
            'source_page_id' => 'INTEGER NULL REFERENCES pages(id) ON DELETE SET NULL',
            'source_revision' => 'INTEGER NULL',
            'translation_status' => "TEXT NOT NULL DEFAULT 'original'",
            'translation_block_map_json' => 'TEXT NULL',
            'content_revision' => 'INTEGER NOT NULL DEFAULT 1',
        ];
        foreach ($translationColumns as $name => $definition) {
            if (!in_array($name, $pageNames, true)) {
                $this->pdo->exec("ALTER TABLE pages ADD COLUMN {$name} {$definition}");
            }
        }
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_pages_language ON pages(language_code)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_pages_translation_source ON pages(source_page_id, language_code)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_pages_translation_group ON pages(translation_group_id)');
        $this->pdo->exec(<<<'SQL'
UPDATE pages
SET access_department = COALESCE((SELECT department FROM users WHERE users.id = pages.author_id), '')
WHERE access_department = ''
SQL);
        $this->pdo->exec(<<<'SQL'
INSERT OR IGNORE INTO page_access_departments (page_id, department, granted_by)
SELECT p.id, TRIM(p.access_department), p.updated_by
FROM pages p
WHERE TRIM(p.access_department) <> ''
  AND NOT EXISTS (
      SELECT 1 FROM page_access_departments pad WHERE pad.page_id = p.id
  )
SQL);
        $this->pdo->exec(<<<'SQL'
INSERT OR IGNORE INTO rag_source_access_departments (source_document_id, department)
SELECT d.id, TRIM(d.access_department)
FROM rag_source_documents d
WHERE TRIM(d.access_department) <> ''
  AND NOT EXISTS (
      SELECT 1 FROM rag_source_access_departments rsad WHERE rsad.source_document_id = d.id
  )
SQL);

        $publicShareColumns = $this->pdo->query('PRAGMA table_info(page_public_shares)')->fetchAll();
        $publicShareNames = array_column($publicShareColumns, 'name');
        $staticPublicationColumns = [
            'public_slug' => 'TEXT NULL CHECK (public_slug IS NULL OR (length(public_slug) BETWEEN 1 AND 80))',
            'enabled' => 'INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1))',
            'publication_locale' => 'TEXT NULL CHECK (publication_locale IS NULL OR (length(publication_locale) BETWEEN 1 AND 35))',
            'published_revision' => 'INTEGER NULL CHECK (published_revision IS NULL OR published_revision > 0)',
            'manifest_json' => 'TEXT NULL',
            'source_hash' => 'TEXT NULL CHECK (source_hash IS NULL OR length(source_hash) = 64)',
            'published_at' => 'TEXT NULL',
            'operation_state' => "TEXT NOT NULL DEFAULT 'idle' CHECK (operation_state IN ('idle', 'publishing', 'stopping', 'failed'))",
            'operation_id' => 'TEXT NULL CHECK (operation_id IS NULL OR (length(operation_id) BETWEEN 1 AND 80))',
            'pending_manifest_json' => 'TEXT NULL',
            'operation_started_at' => 'TEXT NULL',
        ];
        foreach ($staticPublicationColumns as $name => $definition) {
            if (!in_array($name, $publicShareNames, true)) {
                $this->pdo->exec("ALTER TABLE page_public_shares ADD COLUMN {$name} {$definition}");
            }
        }
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_page_public_shares_slug ON page_public_shares(public_slug)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_page_public_shares_enabled ON page_public_shares(enabled, id)');

        $sourceColumns = $this->pdo->query('PRAGMA table_info(rag_source_documents)')->fetchAll();
        $sourceNames = array_column($sourceColumns, 'name');
        if (!in_array('source_schema_version', $sourceNames, true)) {
            $this->pdo->exec('ALTER TABLE rag_source_documents ADD COLUMN source_schema_version INTEGER NOT NULL DEFAULT 4');
        }
        $ragTranslationColumns = [
            'language_code' => "TEXT NOT NULL DEFAULT 'und'",
            'translation_group_id' => 'TEXT NULL',
            'source_page_id' => 'INTEGER NULL',
            'translation_role' => "TEXT NOT NULL DEFAULT 'original'",
            'translation_status' => "TEXT NOT NULL DEFAULT 'original'",
            'source_revision' => 'INTEGER NULL',
            'content_revision' => 'INTEGER NOT NULL DEFAULT 1',
        ];
        foreach ($ragTranslationColumns as $name => $definition) {
            if (!in_array($name, $sourceNames, true)) {
                $this->pdo->exec("ALTER TABLE rag_source_documents ADD COLUMN {$name} {$definition}");
            }
        }

        $chunkColumns = $this->pdo->query('PRAGMA table_info(rag_chunks)')->fetchAll();
        $chunkNames = array_column($chunkColumns, 'name');
        if (!in_array('search_text', $chunkNames, true)) {
            $this->pdo->exec("ALTER TABLE rag_chunks ADD COLUMN search_text TEXT NOT NULL DEFAULT ''");
        }
        $this->pdo->exec(<<<'SQL'
UPDATE rag_chunks
SET search_text = title || char(10) || chunk_summary || char(10) || keywords_json || char(10) || content
WHERE search_text = ''
SQL);
    }

    private function migrateBranding(): void
    {
        $users = $this->pdo->query("SELECT id, email FROM users WHERE email LIKE '%@moku.local'")->fetchAll();
        if (!$users) {
            return;
        }

        $update = $this->pdo->prepare('UPDATE users SET email = ? WHERE id = ?');
        foreach ($users as $user) {
            $email = preg_replace('/@moku\.local$/', '@openconcept.local', (string) $user['email']);
            $update->execute([$email, (int) $user['id']]);
        }
    }
}
